<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Kryption\MediaHub\Actions\Concerns\RunsOnMediaConnection;
use Kryption\MediaHub\Events\FolderMoved;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;

/**
 * MOVING A FOLDER.
 *
 * ⚠️ A FOLDER CANNOT BECOME ITS OWN ANCESTOR. Without this check, the moved branch detaches
 * from the root: it appears in no listing, its files become unreachable through the tree, and
 * the only trace of their existence is the table. This is not an error anyone sees — it is a
 * disappearance.
 *
 * ⚠️ AND THE BYTES MOVE NO MORE THAN THEY DO ON A RENAME. Only `parent_id`, `path` and `depth`
 * change.
 */
final class MoveFolder
{
    use RunsOnMediaConnection;

    public function __construct(
        private readonly FolderTree $tree,
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
    ) {
    }

    public function __invoke(MediaFolder $folder, ?MediaFolder $parent): MediaFolder
    {
        if ($parent !== null && $this->tree->isDescendant($parent, $folder)) {
            throw OperationRejected::because('folder_cycle');
        }

        if ($parent !== null && ((int) $parent->depth) + 1 >= FolderTree::MAX_DEPTH) {
            throw OperationRejected::because('folder_too_deep');
        }

        $this->connection($this->connections)->transaction(function () use ($folder, $parent): void {
            $folder->parent_id = $parent?->getKey();

            /*
             * ⚠️ THE SLUG IS CHECKED AGAIN AT THE NEW LOCATION. It was free under the old
             * parent; nothing says it is free under the new one, and two siblings sharing a
             * slug make the materialised path ambiguous.
             */
            $folder->slug = $this->tree->uniqueSlug((string) $folder->name, $parent?->getKey(), $folder->getKey());

            $this->tree->materialize($folder, $parent);
            $folder->save();

            $this->tree->refreshSubtree($folder);
        });

        $this->events->dispatch(new FolderMoved($folder));

        return $folder;
    }
}
