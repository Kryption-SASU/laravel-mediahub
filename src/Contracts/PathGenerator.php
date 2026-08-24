<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * WHERE A FILE LANDS — the single point of filing.
 *
 * ⚠️ THE PATH IS DERIVED, NEVER RECEIVED FROM THE CLIENT. This is where, and nowhere else,
 * directory traversal is closed.
 *
 * ⚠️ AND IT IS DECIDED ON WRITE, THEN RECORDED — never recomputed on read. Otherwise renaming a
 * folder would become a file migration, with its own window for breakage.
 */
interface PathGenerator
{
    /**
     * A media's folder, ending with a slash.
     *
     * @param  array<string, mixed>  $context  what the caller knows: scope, family, tree
     */
    public function directory(array $context): string;

    /**
     * The path of a derivative, derived from its original's.
     *
     * ⚠️ IT STAYS NEXT TO ITS ORIGINAL: some readers derive this path by string manipulation,
     * and would break if it lived elsewhere.
     */
    public function conversion(string $mediaPath, string $conversion, ?string $extension = null): string;
}
