<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\ValueObjects\ResolvedItems;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * AN ARCHIVE — streamed, and never touching the local disk.
 *
 * ⚠️ "STREAMED" IS NOT ELEGANCE, IT IS THE FIX FOR A LEAK. The original module wrote its ZIP
 * to `public_path('download-Y-m-d-h-i-s.zip')` — inside the web root, under a guessable name
 * (and its `h` is the twelve-hour clock, so 03:14:22 and 15:14:22 share the same file). It was
 * deleted only AFTER a successful send: an interrupted request left it in place, served by the
 * front-end server to anyone who guessed its name.
 *
 * ⚠️ AND OUR BYTES LIVE ON OBJECT STORAGE. Going through a temporary file would mean pulling
 * the whole selection back, compressing it, then sending it: disk consumed, I/O doubled, and a
 * long silence before the first byte. Here, every file passes through.
 *
 * ⚠️ A MISSING FILE IS NOT PASSED OVER IN SILENCE. The original added its files with
 * `$zip->addFile(public_path($file->url), …)` — on a full URL, therefore never a valid local
 * path since the move to the cloud: `addFile` returned `false`, which nobody checked, and the
 * archive closed empty or partial while downloading normally. Here, whatever could not be read
 * is written INSIDE the archive, in a report file. The status code is already gone by then:
 * this is the only way left to say it.
 */
final class BuildArchive
{
    /**
     * ⚠️ THESE TYPES ARE ALREADY COMPRESSED. Running them through `deflate` costs CPU time for
     * no gain, or a negative one — and on a multi-gigabyte archive, that time is exactly what
     * makes the request time out.
     */
    private const ALREADY_COMPRESSED = [
        'application/zip', 'application/gzip', 'application/x-7z-compressed',
        'application/x-rar-compressed', 'application/pdf',
    ];

    public function __construct(
        private readonly FolderTree $tree,
        private readonly FilesystemFactory $filesystems,
        private readonly Config $config,
    ) {
    }

    public function __invoke(ResolvedItems $items, ?string $fileName = null): StreamedResponse
    {
        $entries = $this->collect($items);

        $this->refuseIfOversized($entries);

        $name = $this->safeName($fileName ?? (string) $this->config->get('mediahub.archives.file_name', 'medias.zip'));

        return new StreamedResponse(
            fn () => $this->stream($entries),
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $name,
                    $this->asciiName($name),
                ),
                /*
                 * ⚠️ NO `Content-Length`: the compressed size is only known once the archive
                 * has been written. Announcing a wrong one makes the client cut the connection
                 * at the wrong byte — that is, a truncated archive that looks complete.
                 */
                'Cache-Control' => 'no-store, private',

                /*
                 * ⚠️ WITHOUT THIS HEADER, NGINX MAY BUFFER EVERYTHING before sending anything:
                 * the browser shows nothing for several minutes, and the server holds the whole
                 * archive in memory — precisely what streaming was there to avoid.
                 */
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * WHAT THE ARCHIVE WILL CONTAIN, AND UNDER WHICH NAME.
     *
     * ⚠️ TWO NAMING RULES, BECAUSE THERE ARE TWO INTENTS. A file picked explicitly goes at the
     * root of the archive: the person chose it, not its location. A file pulled in by a FOLDER
     * keeps the visible tree, starting from the chosen folder — otherwise a folder of two
     * hundred photos spread over twelve subfolders comes out flat, and that is unrecoverable.
     *
     * @return array<int, array{media: Media, name: string}>
     */
    private function collect(ResolvedItems $items): array
    {
        $entries = [];
        $seen = ['media' => [], 'names' => []];

        /*
         * ⚠️ THE ORDER IS IMPOSED, AND THAT IS NOT COMFORT. Resolution returns the models in
         * whichever order the database yields them — on a random route key, therefore in a
         * random order. The same request twice would produce two different archives; worse,
         * the suffix telling two namesakes apart would stick to one of them and then to the
         * other, and there would be no way to know which is which. Measured on the bench:
         * without sorting, the two files came out in the reverse order of their creation.
         */
        $chosen = $items->media
            ->sortBy(static fn (Media $media): array => [(string) $media->name, (string) $media->getKey()])
            ->values();

        foreach ($chosen as $media) {
            $this->push($entries, $seen, $media, $this->fileName($media));
        }

        foreach ($items->folders as $folder) {
            $keys = $this->tree->subtreeKeys($folder);

            /*
             * ⚠️ THE PREFIX STRIPPED IS THE PARENT'S, not the chosen folder's. Archiving
             * "Root/Child" must produce "child/…" and not "…": without its own name in front,
             * the content spills into the root of the archive and mixes with the rest of the
             * selection.
             */
            $parent = $folder->parent_id === null ? null : $folder->parent;
            $prefix = $parent === null ? '' : rtrim((string) $parent->path, '/').'/';

            /*
             * ⚠️ SORTED ON PATH THEN ON NAME — therefore in tree order. A plain sort by name
             * mixes the levels: a file from a sub-subfolder ends up between two files of the
             * root, and the archive listing no longer looks like what the person saw on screen.
             */
            $inside = Media::query()
                ->with('folder')
                ->whereIn(Media::column('folder_id'), $keys)
                ->get()
                ->sortBy(static fn (Media $media): array => [
                    (string) ($media->folder?->path ?? ''),
                    (string) $media->name,
                    (string) $media->getKey(),
                ])
                ->values();

            foreach ($inside as $media) {
                $path = (string) ($media->folder?->path ?? '');
                $relative = $prefix !== '' && str_starts_with($path, $prefix)
                    ? substr($path, strlen($prefix))
                    : $path;

                $this->push($entries, $seen, $media, trim($relative, '/').'/'.$this->fileName($media));
            }
        }

        return $entries;
    }

    /**
     * ⚠️ TWO SEPARATE REGISTERS, NOT ONE. Identifiers already taken and entry names already
     * taken do not live in the same space: nothing stops a file from being named like an
     * identifier, and the collision would be silent — a file missing from the archive.
     *
     * @param  array<int, array{media: Media, name: string}>  $entries
     * @param  array{media: array<string, true>, names: array<string, true>}  $seen
     */
    private function push(array &$entries, array &$seen, Media $media, string $name): void
    {
        $key = (string) $media->getKey();

        /* The same file picked twice — directly and through its folder — goes in only once. */
        if (isset($seen['media'][$key])) {
            return;
        }

        $seen['media'][$key] = true;

        $entries[] = ['media' => $media, 'name' => $this->uniqueEntry($seen['names'], $this->entryName($name))];
    }

    /**
     * ⚠️ TWO FILES MAY CARRY THE SAME DISPLAYED NAME IN THE SAME PLACE — nothing forbids it,
     * only the name on disk is unique. Two entries with the same name in a ZIP are accepted by
     * the format, and the extractor overwrites one of them: a file would be lost without any
     * error saying so, neither when building nor when extracting.
     *
     * @param  array<string, true>  $names
     */
    private function uniqueEntry(array &$names, string $name): string
    {
        if (! isset($names[$name])) {
            $names[$name] = true;

            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));
        $suffix = 2;

        $candidate = static fn (int $rank): string => $base.'-'.$rank.($extension === '' ? '' : '.'.$extension);

        while (isset($names[$candidate($suffix)])) {
            $suffix++;
        }

        $names[$candidate($suffix)] = true;

        return $candidate($suffix);
    }

    /**
     * ⚠️ NO ENTRY CLIMBS OUT OF A FOLDER. A `..` in an entry name is the "zip slip": on
     * extraction, the file lands outside the destination folder. We are not on the receiving
     * end here — we would be BUILDING it, and the archive would go and do the damage elsewhere.
     */
    private function entryName(string $raw): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $raw)) as $segment) {
            $segment = trim(str_replace("\0", '', $segment));
            $segment = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $segment);

            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? 'media' : implode('/', $segments);
    }

    private function fileName(Media $media): string
    {
        $name = trim((string) $media->name);
        $extension = strtolower((string) $media->extension);

        if ($name === '') {
            return (string) $media->file_name;
        }

        return $extension === '' || str_ends_with(strtolower($name), '.'.$extension)
            ? $name
            : $name.'.'.$extension;
    }

    /**
     * ⚠️ THE BOUNDS ARE CHECKED BEFORE THE FIRST BYTE, and that is the only window where a
     * refusal can still be an HTTP status code. Once the stream has started, a
     * `max_execution_time` falling in the middle produces a TRUNCATED archive that nothing
     * distinguishes from a complete one: it downloads, it opens, and files are missing.
     *
     * ⚠️ AND AN EMPTY SELECTION IS A REFUSAL, not an empty archive. A ZIP with zero useful
     * bytes that downloads normally reads as a success.
     *
     * @param  array<int, array{media: Media, name: string}>  $entries
     */
    private function refuseIfOversized(array $entries): void
    {
        if ($entries === []) {
            throw OperationRejected::because('archive_empty');
        }

        $maxFiles = (int) $this->config->get('mediahub.archives.max_files', 1000);

        if ($maxFiles > 0 && count($entries) > $maxFiles) {
            throw OperationRejected::because('archive_too_many_files');
        }

        $maxBytes = (int) $this->config->get('mediahub.archives.max_bytes', 2147483648);

        if ($maxBytes <= 0) {
            return;
        }

        $total = 0;

        foreach ($entries as $entry) {
            $total += (int) $entry['media']->size;
        }

        if ($total > $maxBytes) {
            throw OperationRejected::because('archive_too_large');
        }
    }

    /**
     * @param  array<int, array{media: Media, name: string}>  $entries
     */
    private function stream(array $entries): void
    {
        $zip = new ZipStream(
            /*
             * ⚠️ `sendHttpHeaders` SET TO FALSE: the headers are already set by the response.
             * Leaving it at `true` would write a second set of them in the middle of the body.
             */
            sendHttpHeaders: false,

            /*
             * ⚠️ THE ZERO HEADER IS WHAT MAKES STREAMING POSSIBLE. A file's compressed size is
             * only known once it has been written; without a data descriptor, the output would
             * have to be seeked backwards — therefore a file, therefore a disk.
             */
            defaultEnableZeroHeader: true,
        );

        $missing = [];

        foreach ($entries as $entry) {
            $media = $entry['media'];

            $handle = $this->open($media);

            if ($handle === null) {
                $missing[] = $entry['name'];

                continue;
            }

            try {
                $zip->addFileFromStream(
                    fileName: $entry['name'],
                    stream: $handle,
                    compressionMethod: $this->compression((string) $media->mime_type),
                );
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        if ($missing !== []) {
            $zip->addFile(
                fileName: (string) $this->config->get('mediahub.archives.report_name', 'MISSING.txt'),
                data: implode("\n", $missing)."\n",
            );
        }

        $zip->finish();
    }

    /**
     * ⚠️ A MISSING OBJECT DOES NOT BRING THE ARCHIVE DOWN. By this point the status code is
     * already gone: throwing would cut the connection halfway and hand over an unreadable file,
     * when everything before it is good. What is missing is recorded, and the archive finishes.
     *
     * ⚠️ BOTH EXITS ARE COVERED, BUT ONLY ONE IS PROVEN. Measured on the bench: a
     * `throw => false` disk returns `null` on a missing object, a `throw => true` disk raises
     * `UnableToReadFile` — never `false`. The `is_resource()` therefore only serves a host
     * adapter returning something else, and NO test can catch it out: replacing it with a plain
     * `return $handle;` leaves the suite green. It stays because the return type is guaranteed
     * by no contract, and it is written here that it is not proven.
     *
     * @return resource|null
     */
    private function open(Media $media): mixed
    {
        try {
            $handle = $this->filesystems->disk((string) $media->disk)->readStream((string) $media->path);
        } catch (\Throwable) {
            return null;
        }

        return is_resource($handle) ? $handle : null;
    }

    private function compression(string $mimeType): CompressionMethod
    {
        $mimeType = strtolower($mimeType);

        $family = explode('/', $mimeType)[0];

        if (in_array($family, ['image', 'video', 'audio'], true) || in_array($mimeType, self::ALREADY_COMPRESSED, true)) {
            return CompressionMethod::STORE;
        }

        return CompressionMethod::DEFLATE;
    }

    private function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', '"', "\r", "\n", "\0"], '', $name);
        $name = trim($name);

        return $name === '' ? 'medias.zip' : $name;
    }

    private function asciiName(string $name): string
    {
        $ascii = trim((string) preg_replace('/[^\x20-\x7E]/', '', $this->safeName($name)));

        return $ascii === '' ? 'medias.zip' : $ascii;
    }
}
