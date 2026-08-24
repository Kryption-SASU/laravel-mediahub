<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Kryption\MediaHub\Actions\Concerns\RunsOnMediaConnection;
use Kryption\MediaHub\Events\ItemsPurged;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * DELETING FOR GOOD — the only action in this package that destroys anything.
 *
 * ⚠️ THE ROWS FIRST, THE BYTES AFTER THE `commit()`. The reverse order is the expensive one: a
 * `rollback` after the files have been erased leaves a perfectly consistent database naming
 * objects that are gone. In this direction, the worst possible failure is an object without a
 * row — an orphan nobody sees, and that a sweep finds again. In the other, it is a row without
 * an object: a dead image, visible to everyone, and beyond repair.
 *
 * ⚠️ THE BYTES ONLY GO IF NOTHING CLAIMS THEM ANY MORE. Two rows can point at the same object —
 * a deduplication that reuses, a data migration, a `table` mode plugged onto a schema that
 * allows it. Deleting without checking means puncturing somebody else's image. And the check is
 * made OUTSIDE THE SCOPE: whoever holds a reference is precisely the one we cannot see.
 *
 * ⚠️ THE DERIVATIVES GO WITH IT, FILES INCLUDED. The foreign key cleans up the rows; it knows
 * nothing about the storage. That is the classic source of orphaned thumbnails.
 */
final class ForceDeleteItems
{
    use RunsOnMediaConnection;

    public function __construct(
        private readonly FolderTree $tree,
        private readonly FilesystemFactory $filesystems,
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
    ) {
    }

    public function __invoke(ResolvedItems $items): ResolvedItems
    {
        if ($items->isEmpty()) {
            return $items;
        }

        [$media, $folders] = $this->expand($items);

        if ($media->isEmpty() && $folders === []) {
            return $items;
        }

        $conversions = $media->isEmpty()
            ? new Collection()
            : MediaConversion::query()->whereIn('media_id', $media->map(static fn ($model) => $model->getKey())->all())->get();

        $toErase = $this->paths($media, $conversions);

        $this->connection($this->connections)->transaction(function () use ($media, $folders, $conversions): void {
            if ($conversions->isNotEmpty()) {
                MediaConversion::query()->whereKey($conversions->map(static fn ($model) => $model->getKey())->all())->delete();
            }

            if ($media->isNotEmpty()) {
                Media::withTrashed()->whereKey($media->map(static fn ($model) => $model->getKey())->all())->forceDelete();
            }

            if ($folders !== []) {
                MediaFolder::withTrashed()->whereKey($folders)->forceDelete();
            }
        });

        $this->forget($toErase);

        $this->events->dispatch(new ItemsPurged($items->media, $items->folders));

        return $items;
    }

    /**
     * EVERYTHING THE BATCH ACTUALLY CARRIES: the named media, plus the content of the named
     * folders and of all their descendants — trash included.
     *
     * @return array{0: Collection<int, Media>, 1: array<int, mixed>}
     */
    private function expand(ResolvedItems $items): array
    {
        $folders = [];

        foreach ($items->folders as $folder) {
            foreach ($this->tree->subtreeKeys($folder) as $key) {
                $folders[] = $key;
            }
        }

        $folders = array_values(array_unique($folders, SORT_REGULAR));

        $media = $items->media;

        if ($folders !== []) {
            $media = $media->merge(
                Media::withTrashed()->whereIn(Media::column('folder_id'), $folders)->get()
            );
        }

        return [$media->unique(static fn (Media $item) => $item->getKey())->values(), $folders];
    }

    /**
     * @param  Collection<int, Media>  $media
     * @param  Collection<int, MediaConversion>  $conversions
     * @return array<int, array{disk: string, path: string, conversion: bool}>
     */
    private function paths(Collection $media, Collection $conversions): array
    {
        $paths = [];

        foreach ($media as $item) {
            $paths[] = ['disk' => (string) $item->disk, 'path' => (string) $item->path, 'conversion' => false];
        }

        foreach ($conversions as $conversion) {
            $paths[] = ['disk' => (string) $conversion->disk, 'path' => (string) $conversion->path, 'conversion' => true];
        }

        return $paths;
    }

    /**
     * @param  array<int, array{disk: string, path: string, conversion: bool}>  $paths
     */
    private function forget(array $paths): void
    {
        foreach ($paths as $target) {
            if ($target['path'] === '') {
                continue;
            }

            if ($this->stillReferenced($target['disk'], $target['path'], $target['conversion'])) {
                continue;
            }

            /*
             * ⚠️ AN ERASURE THAT FAILS DOES NOT FAIL THE OPERATION. The rows are already gone:
             * raising here would hand somebody an error for work that did take place, and would
             * invite them to repeat a deletion with nothing left to delete. What remains is an
             * orphan, which the sweep will find.
             */
            try {
                $this->filesystems->disk($target['disk'])->delete($target['path']);
            } catch (\Throwable) {
                // nothing: the object stays orphaned, which is the lesser evil
            }
        }
    }

    /**
     * ⚠️ WITHOUT THE SCOPE, AND WITH THE TRASH. Both matter: whoever holds a reference is by
     * definition invisible from here, and a trashed row can be restored — so its bytes are
     * still claimed.
     */
    private function stillReferenced(string $disk, string $path, bool $conversion): bool
    {
        if ($conversion) {
            return MediaConversion::query()
                ->where('disk', $disk)
                ->where('path', $path)
                ->exists();
        }

        return Media::withoutMediaScope()
            ->where(Media::column('disk'), $disk)
            ->where(Media::column('path'), $path)
            ->exists();
    }
}
