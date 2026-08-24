<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Str;
use Kryption\MediaHub\Contracts\FileNamer;

/**
 * THE NAME ON DISK — normalised, and free within its folder.
 *
 * ⚠️ UNIQUENESS IS CHECKED AGAINST THE STORAGE, AND THAT IS THE WHOLE POINT. The original
 * module tested the file's existence with a LOCAL filesystem function, while its objects lived
 * on remote storage: the check therefore NEVER detected anything. Two uploads of the same name
 * into the same folder silently overwrote the first object, while creating two rows naming the
 * same path.
 *
 * This is not a hypothesis: a tidy-up carried out on real data found four avatar rows pointing
 * at one and the same object.
 *
 * ⚠️ AND THE EXTENSION DOES NOT COME FROM THE SUPPLIED NAME. It is passed by the caller, who
 * derived it from the real type. A name with nothing to normalise must never produce a file
 * with an empty extension — two production files are called `…272552.`, with a trailing dot and
 * nothing behind: they cannot be found, and never will be.
 */
final class SluggedFileNamer implements FileNamer
{
    /** The bound on the suffix: beyond it, we stop searching and cut. */
    private const ATTEMPTS = 50;

    public function __construct(private readonly FilesystemFactory $filesystems)
    {
    }

    public function sanitize(string $originalName): string
    {
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));

        return $base === '' ? 'media' : $base;
    }

    public function unique(string $name, string $disk, string $directory): string
    {
        $storage = $this->filesystems->disk($disk);
        $folder = trim($directory, '/');
        $prefix = $folder === '' ? '' : $folder.'/';

        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.'.$extension;

        $candidate = $base.$suffix;

        for ($i = 1; $i <= self::ATTEMPTS; $i++) {
            if (! $storage->exists($prefix.$candidate)) {
                return $candidate;
            }

            $candidate = $base.'-'.$i.$suffix;
        }

        /*
         * ⚠️ THE LAST RESORT IS NOT A FAILURE. Fifty namesakes in one folder is already an
         * anomaly; refusing the upload at that point would punish the person for a mess that is
         * not theirs. An unpredictable suffix settles it, and the DISPLAYED name stays the one
         * they chose.
         */
        return $base.'-'.Str::lower(Str::random(8)).$suffix;
    }
}
