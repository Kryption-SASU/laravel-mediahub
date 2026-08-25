<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Translation\Translator;
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
 * ⚠️ THE COPY IS MARKED BY ITS NAME, OR IT IS LOST. The file name on disk was already made
 * unique; the name people read was replicated as it stood, so a duplicate arrived as a second
 * tile with the same name, in the same folder, with nothing to tell the two apart — and no way
 * afterwards to know which one had been edited since.
 *
 * ⚠️ AND THE DERIVATIVES ARE NOT COPIED, THEY ARE REBUILT. Copying their files would mean
 * copying their rows, their states and their errors too — including a "failed" that would be
 * dragged along indefinitely. The queue regenerates them, and the original stays served in the
 * meantime.
 */
final class CopyMedia
{
    /** ⚠️ HOW MANY NAMES ARE TRIED BEFORE GIVING UP IS NOT A GUESS: with `n` siblings, at most
     * `n + 1` candidates can be taken, so one more than that is free by counting alone. */
    private const NAME_ATTEMPTS_MARGIN = 2;

    public function __construct(
        private readonly Translator $translator,
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
            $copy->name = $this->copyName((string) $media->name, $copy->folder_id);
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
     * THE NAME A COPY IS GIVEN — marked, and not the same as its neighbour's.
     *
     * ⚠️ THE MARK IS TRANSLATED, because it is written into the data and read for ever after. A
     * French library whose copies are all called "(copy)" is one where the only fix is renaming
     * every file by hand.
     *
     * ⚠️ AND A SECOND COPY IS NUMBERED RATHER THAN SUFFIXED TWICE. Duplicating the same file
     * three times would otherwise give "photo (copy) (copy) (copy)" — which is not a name, it is
     * a record of how many times somebody clicked.
     *
     * ⚠️ THE NAMES IT COMPARES AGAINST COME THROUGH THE MODEL, therefore through the scope: the
     * numbering never reveals that a name is taken in a folder the caller cannot see.
     */
    private function copyName(string $original, mixed $folderKey): string
    {
        $taken = Media::query()
            ->atParent('folder_id', $folderKey)
            ->pluck(Media::column('name'))
            ->all();

        $first = (string) $this->translator->get('mediahub::media.copy', ['name' => $original]);

        if (! in_array($first, $taken, true)) {
            return $first;
        }

        $limit = count($taken) + self::NAME_ATTEMPTS_MARGIN;

        for ($number = 2; $number <= $limit; $number++) {
            $candidate = (string) $this->translator->get(
                'mediahub::media.copy_numbered',
                ['name' => $original, 'number' => $number],
            );

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        /* ⚠️ UNREACHABLE BY COUNTING, AND STILL WRITTEN DOWN. A loop whose exit depends on
         * arithmetic done three lines earlier is one somebody will later "simplify"; falling
         * back to the plain mark says what happens if they get it wrong, rather than returning
         * an empty name. */
        return $first;
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
