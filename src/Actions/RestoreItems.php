<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Kryption\MediaHub\Actions\Concerns\RunsOnMediaConnection;
use Kryption\MediaHub\Events\ItemsRestored;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * COMING BACK OUT OF THE TRASH.
 *
 * ⚠️ A FOLDER RESTORES WHAT ITS DELETION TOOK, recognised by the exact instant shared by the
 * whole batch. Whatever was thrown away BEFORE, separately, stays in the trash: nobody asked
 * for it back, and bringing it back "because it was there" would undo a decision somebody made.
 *
 * ⚠️ AND THE ANCESTORS COME BACK WITH IT. Restoring a file into a folder that stayed in the
 * trash makes it invisible: it appears in no listing, it is no longer in the trash, and nothing
 * on screen says where it went. A restore nobody can see is not a restore.
 *
 * ⚠️ THE INSTANT COMPARISON IS MADE TO THE SECOND. Two distinct deletions within the same
 * second, on the same branch, would be conflated — that is the price of a `timestamp` column
 * without fractions, and the consequence is benign: one file comes back with the folder.
 */
final class RestoreItems
{
    use RunsOnMediaConnection;

    public function __construct(
        private readonly FolderTree $tree,
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
    ) {
    }

    public function __invoke(ResolvedItems $items): ResolvedItems
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $this->connection($this->connections)->transaction(function () use ($items): void {
            foreach ($items->folders as $folder) {
                $this->restoreSubtree($folder);
                $this->restoreAncestors($folder);
            }

            foreach ($items->media as $media) {
                Media::withTrashed()
                    ->whereKey($media->getKey())
                    ->update(['deleted_at' => null]);

                $parent = $media->folder_id === null
                    ? null
                    : MediaFolder::withTrashed()->find($media->folder_id);

                if ($parent !== null) {
                    $this->restoreAncestors($parent, true);
                }
            }
        });

        $this->events->dispatch(new ItemsRestored($items->media, $items->folders));

        return $items;
    }

    /** The folder, its descendants, and the files that went with it at the same instant. */
    private function restoreSubtree(MediaFolder $folder): void
    {
        $instant = $folder->deleted_at;

        if ($instant === null) {
            return;
        }

        $keys = $this->tree->subtreeKeys($folder);

        Media::withTrashed()
            ->whereIn(Media::column('folder_id'), $keys)
            ->where('deleted_at', $instant)
            ->update(['deleted_at' => null]);

        MediaFolder::withTrashed()
            ->whereKey($keys)
            ->where('deleted_at', $instant)
            ->update(['deleted_at' => null]);
    }

    /**
     * ⚠️ WE CLIMB WITHOUT AN INSTANT CONDITION. An ancestor may have been deleted long before;
     * leaving it in the trash would make what has just been restored invisible, which is the
     * opposite of the service being asked for.
     */
    private function restoreAncestors(MediaFolder $folder, bool $includeSelf = false): void
    {
        $current = $includeSelf ? $folder : $this->parent($folder);
        $guard = 0;

        while ($current !== null && $guard++ < FolderTree::MAX_DEPTH) {
            if ($current->deleted_at !== null) {
                MediaFolder::withTrashed()
                    ->whereKey($current->getKey())
                    ->update(['deleted_at' => null]);
            }

            $current = $this->parent($current);
        }
    }

    private function parent(MediaFolder $folder): ?MediaFolder
    {
        return $folder->parent_id === null
            ? null
            : MediaFolder::withTrashed()->find($folder->parent_id);
    }
}
