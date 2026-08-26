<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * BRINGING AN OBJECT DOWN TO A LOCAL FILE, BECAUSE A PROGRAM CANNOT READ A DISK.
 *
 * ⚠️ FFMPEG AND POPPLER TAKE A PATH, AND OUR BYTES LIVE ON OBJECT STORAGE. There is no way round
 * it: what is handed to a program has to exist on this machine's filesystem.
 *
 * ⚠️ AND A LOCAL PATH IS THE SAFE CHOICE, not merely the necessary one. ffmpeg reads network
 * protocols perfectly well, and handing it a URL would be faster — it would also let a program
 * with a long history of parser flaws make requests on our behalf, which is a class of problem
 * nobody wants for a thumbnail. Nothing here ever gives it an address.
 *
 * ⚠️ STREAMED, NEVER READ INTO A STRING. `$storage->get()` on a five-hundred-megabyte video is
 * five hundred megabytes of PHP memory, and the process dies before ffmpeg is even started —
 * on exactly the files a thumbnail is most wanted for.
 *
 * ⚠️ AND BOUNDED, BECAUSE PULLING TWO GIGABYTES FOR A 256-PIXEL IMAGE IS ABSURD. Past the
 * ceiling there is no thumbnail rather than a transfer nobody asked for. The file still
 * uploads, still downloads, still plays: what it loses is a picture.
 */
final class SourceFile
{
    /** ⚠️ ITS OWN PREFIX, so that what this leaves behind can be recognised and swept. */
    private const PREFIX = 'mediahub-src-';

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly Config $config,
    ) {
    }

    /**
     * How much this machine is willing to pull down for a thumbnail. Zero means no ceiling.
     */
    public function ceiling(): int
    {
        return max(0, (int) $this->config->get('mediahub.tools.max_source_bytes', 0));
    }

    /**
     * Runs `$work` with a local copy of the object, and takes the copy away afterwards.
     *
     * ⚠️ THE CLEANUP IS IN A `finally`, INCLUDING WHEN THE WORK THREW. A failed conversion that
     * leaves half a video in the temporary directory is a disk that fills up over weeks, and
     * the first sign of it is something entirely unrelated refusing to write.
     *
     * @template T
     * @param  callable(string $local): T  $work
     * @return T
     */
    public function withLocalCopy(string $disk, string $path, callable $work): mixed
    {
        $storage = $this->filesystems->disk($disk);
        $ceiling = $this->ceiling();

        /*
         * ⚠️ THE SIZE IS ASKED FOR BEFORE ANYTHING IS PULLED. Checking as the bytes arrive would
         * mean the transfer this exists to avoid has already happened.
         */
        if ($ceiling > 0) {
            $weight = (int) $storage->size($path);

            if ($weight > $ceiling) {
                throw new \RuntimeException('conversion_source_too_large');
            }
        }

        $handle = $storage->readStream($path);

        if (! is_resource($handle)) {
            throw new \RuntimeException('conversion_source_unreadable');
        }

        $local = (string) tempnam(sys_get_temp_dir(), self::PREFIX);
        $sink = @fopen($local, 'wb');

        if (! is_resource($sink)) {
            fclose($handle);
            @unlink($local);

            throw new \RuntimeException('conversion_scratch_unwritable');
        }

        try {
            stream_copy_to_stream($handle, $sink);
            fclose($sink);
            $sink = null;

            return $work($local);
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }

            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($local);
        }
    }

    /**
     * A path a program may write its answer to, taken away afterwards whatever happens.
     *
     * ⚠️ THE FILE IS CREATED AND THEN REMOVED BEFORE THE PROGRAM RUNS. `tempnam` makes an empty
     * file, and several of these tools refuse to overwrite one — or, worse, append to it. What
     * is wanted is the NAME, reserved, with nothing at it.
     *
     * @template T
     * @param  callable(string $target): T  $work
     * @return T
     */
    public function withScratchFile(string $extension, callable $work): mixed
    {
        $target = (string) tempnam(sys_get_temp_dir(), self::PREFIX).'.'.$extension;

        @unlink(substr($target, 0, -strlen($extension) - 1));

        try {
            return $work($target);
        } finally {
            @unlink($target);
        }
    }
}
