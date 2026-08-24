<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * THE NAME ON DISK — normalised, and unique within its folder.
 *
 * ⚠️ TWO DISTINCT NAMES COEXIST in this product: the one the person sees and can change, and
 * this one. Confusing them makes a file's location depend on human typing.
 *
 * ⚠️ AND UNIQUENESS IS CHECKED AGAINST THE STORAGE, not against a local filesystem. The
 * original module tested existence with a local function while its objects lived on remote
 * storage: the check never detected anything, and two uploads of the same name silently
 * overwrote the first object.
 */
interface FileNamer
{
    /** Normalises a name supplied by a human — no accents, no spaces, no separators. */
    public function sanitize(string $originalName): string;

    /** Returns a name that is free in this folder, on this disk. */
    public function unique(string $name, string $disk, string $directory): string;
}
