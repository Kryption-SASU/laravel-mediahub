<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;

/**
 * THE STORAGE IS BADLY DECLARED — and the application does not start.
 *
 * ⚠️ THIS ONE RAISES AT BOOT, NOT AT RUNTIME, and that is deliberate. A mistake about where the
 * bytes go cannot be recovered from: by the time anyone notices, files have gone somewhere, and
 * "somewhere" may be the web root. Better to refuse to start than to find out a week later
 * where the product has been writing.
 *
 * ⚠️ AND IT IS THE OPPOSITE OF THE IMAGE DRIVER, WHICH FALLS BACK SILENTLY ON "I CAN DO
 * NOTHING". That is not an inconsistency: a typo on an image driver costs a thumbnail, a typo
 * on the storage misplaces the originals.
 */
final class StorageMisconfigured extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
