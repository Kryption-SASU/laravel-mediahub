<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kryption\MediaHub\Contracts\FileNamer;
use Kryption\MediaHub\Contracts\QuotaPolicy;
use Kryption\MediaHub\Events\MediaCopied;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Exceptions\QuotaExceeded;
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * COPYING A MEDIA — a real copy, bytes included.
 *
 * ⚠️ DEDUPLICATION DOES NOT APPLY HERE, AND THAT IS DELIBERATE. On write, two uploads of the
 * same content may legitimately return the same row: nobody asked for two objects. A copy, on
 * the other hand, is an EXPLICIT request for a second instance — most often in order to make
 * one of them diverge. Reusing the existing row would make the action silently inert: the
 * screen would report success, and nothing would have been created.
 *
 * ⚠️ THE QUOTA IS CHECKED BEFORE THE WRITE. A copy takes as much room as its original; it is
 * the simplest way past a limit without ever uploading anything.
 *
 * ⚠️ AND THE DERIVATIVES ARE NOT COPIED, THEY ARE REBUILT. Copying their files would mean
 * copying their rows, their states and their errors too — including a "failed" that would be
 * dragged along indefinitely. The queue regenerates them, and the original stays served in the
 * meantime.
 */
final class CopyMedia
{
    public function __construct(
        private readonly FileNamer $namer,
        private readonly QuotaPolicy $quota,
        private readonly FilesystemFactory $filesystems,
        private readonly Dispatcher $events,
    ) {
    }

    public function __invoke(Media $media, ?MediaFolder $folder = null): Media
    {
        $disk = (string) $media->disk;
        $source = (string) $media->path;
        $size = (int) $media->size;
        $scope = $media->scope_key === null ? null : (string) $media->scope_key;

        if (! $this->quota->allows($scope, $size)) {
            throw new QuotaExceeded($scope, $size);
        }

        $directory = $this->directory($source);
        $name = $this->namer->unique((string) $media->file_name, $disk, $directory);
        $target = $directory.$name;

        $this->copyBytes($disk, $source, $target);

        try {
            $copy = $media->replicate(['uuid', 'path', 'file_name', 'created_at', 'updated_at', 'deleted_at']);
            $copy->path = $target;
            $copy->file_name = $name;
            $copy->folder_id = $folder === null ? $media->folder_id : $folder->getKey();
            $copy->save();
        } catch (\Throwable $e) {
            /* The row could not be born: we do not leave its bytes behind. */
            $this->filesystems->disk($disk)->delete($target);

            throw $e;
        }

        GenerateConversionsJob::dispatch($copy);

        $this->events->dispatch(new MediaCopied($media, $copy));

        return $copy;
    }

    /**
     * ⚠️ STREAMED, NEVER IN MEMORY. With a ceiling counted in gigabytes, reading the whole file
     * before writing it back is the difference between "it works" and "it kills the process".
     */
    private function copyBytes(string $disk, string $source, string $target): void
    {
        $storage = $this->filesystems->disk($disk);

        $handle = $storage->readStream($source);

        if ($handle === null || $handle === false) {
            throw OperationRejected::because('source_unreadable');
        }

        try {
            $storage->writeStream($target, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * ⚠️ THE COPY STAYS NEXT TO ITS ORIGINAL, whatever folder is displayed. The `PathGenerator`
     * decides the filing on WRITE; replaying it here would send the copy into today's folder,
     * away from the original — and two objects believed to be neighbours would no longer be
     * when the time came for a data migration.
     */
    private function directory(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position + 1);
    }
}
