<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Kryption\MediaHub\Actions\Concerns\RunsOnMediaConnection;
use Kryption\MediaHub\Events\ItemsTrashed;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * PUTTING THINGS IN THE TRASH — and nothing else.
 *
 * ⚠️ NO BYTE IS TOUCHED. That is the very definition of a trash, and it is what makes restoring
 * possible. Any cleanup operation that forgets it destroys what was restorable.
 *
 * ⚠️ A FOLDER CARRIES ITS CONTENT WITH IT, DESCENDANTS INCLUDED. A deleted folder whose files
 * stay visible leaves rows attached to an absent parent: 6,302 of them were counted on a real
 * case, invisible on screen and very much present in the database.
 *
 * ⚠️ AND THE WHOLE BATCH CARRIES THE SAME DELETION INSTANT. That is what lets the restore give
 * back EXACTLY what this operation took — no more, no less. Without it, restoring a folder
 * would bring back files somebody deliberately threw away the week before.
 *
 * ⚠️ WHAT IS ALREADY IN THE TRASH IS NOT TOUCHED AGAIN. Rewriting its deletion instant would
 * attach it to this operation, and would bring it back along with it.
 */
final class TrashItems
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

        $instant = Carbon::now();

        $this->connection($this->connections)->transaction(function () use ($items, $instant): void {
            foreach ($items->folders as $folder) {
                $keys = $this->tree->subtreeKeys($folder);

                /* The queries below carry the soft-delete scope: what is already gone is excluded. */
                Media::query()
                    ->whereIn(Media::column('folder_id'), $keys)
                    ->update(['deleted_at' => $instant, 'updated_at' => $instant]);

                MediaFolder::query()
                    ->whereKey($keys)
                    ->update(['deleted_at' => $instant, 'updated_at' => $instant]);
            }

            if ($items->media->isNotEmpty()) {
                Media::query()
                    ->whereKey($items->media->map(static fn ($model) => $model->getKey())->all())
                    ->update(['deleted_at' => $instant, 'updated_at' => $instant]);
            }
        });

        $this->events->dispatch(new ItemsTrashed($items->media, $items->folders));

        return $items;
    }
}
