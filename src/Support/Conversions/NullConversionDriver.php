<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Kryption\MediaHub\Contracts\ConversionDriver;

/**
 * THE DRIVER THAT CAN DO NOTHING, AND SAYS SO.
 *
 * ⚠️ IT EXISTS SO THAT "NO THUMBNAILS" IS A NORMAL STATE, not a failure. A host that only
 * stores documents, a runtime image without a graphics library: the package must install there
 * and be useful. An impossible derivative never prevents the original from being served.
 *
 * ⚠️ AND IT RAISES IF CALLED ANYWAY. Answering no to `supports()` and then producing an empty
 * result would be worse than anything: the caller would believe it had a thumbnail.
 */
final class NullConversionDriver implements ConversionDriver
{
    public function supports(string $mimeType): bool
    {
        return false;
    }

    /**
     * ⚠️ IT DOES NOT EVEN CLAIM AN OUTPUT FORMAT. Answering "image/png" would suggest a PNG is
     * coming; we return the source, which announces no transformation at all. Nothing calls it
     * anyway: `supports()` has already answered no.
     */
    public function outputMimeType(string $sourceMimeType): string
    {
        return strtolower(trim($sourceMimeType));
    }

    public function convert(string $disk, string $path, string $target, array $definition): array
    {
        throw new \LogicException(
            'No conversion driver is available: `supports()` had answered no.'
        );
    }

    /** ⚠️ It draws nothing, so it needs nothing. */
    public function needsAProgram(): bool
    {
        return false;
    }

}
