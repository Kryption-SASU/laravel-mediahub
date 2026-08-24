<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Kryption\MediaHub\Actions\Concerns\RunsOnMediaConnection;
use Kryption\MediaHub\Events\FolderRenamed;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;

/**
 * RENAMING A FOLDER.
 *
 * ⚠️ RENAMING CHANGES THE SLUG, THEREFORE THE MATERIALISED PATH OF EVERY DESCENDANT. That is
 * the reason for the transaction: rewriting a hundred paths and stopping at the fiftieth would
 * leave a tree half of which names a parent that no longer exists under that name.
 *
 * ⚠️ AND NO BYTE MOVES. The files' path was decided on write and recorded; it is not recomputed
 * from the tree. That is precisely what makes this rename instant instead of a migration — and
 * it is what the original module lacked, filing its bytes according to the displayed tree.
 */
final class RenameFolder
{
    use RunsOnMediaConnection;

    public function __construct(
        private readonly FolderTree $tree,
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
    ) {
    }

    public function __invoke(MediaFolder $folder, string $name): MediaFolder
    {
        $name = trim($name);

        if ($name === '') {
            throw OperationRejected::because('folder_name_required');
        }

        $this->connection($this->connections)->transaction(function () use ($folder, $name): void {
            $parent = $folder->parent_id === null
                ? null
                : MediaFolder::withTrashed()->find($folder->parent_id);

            $folder->name = $name;
            $folder->slug = $this->tree->uniqueSlug($name, $folder->parent_id, $folder->getKey());

            $this->tree->materialize($folder, $parent);
            $folder->save();

            $this->tree->refreshSubtree($folder);
        });

        $this->events->dispatch(new FolderRenamed($folder));

        return $folder;
    }
}
