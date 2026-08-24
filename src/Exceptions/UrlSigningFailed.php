<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;

/**
 * NO SIGNED URL COULD BE BUILT — and we say so instead of serving the other one.
 *
 * ⚠️ THAT IS THE WHOLE POINT OF THIS CLASS. The natural fallback when signing fails is to
 * return the storage's public URL: the link works, the screen renders, and nobody notices that
 * the application is now handing out permanent public links to private files. A visible failure
 * is better than an invisible leak.
 */
final class UrlSigningFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
