<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;
use Kryption\MediaHub\Exceptions\Concerns\RendersAsJson;

/**
 * THE UPLOAD IS REFUSED, AND WE SAY WHY.
 *
 * ⚠️ THE REASON IS A KEY, NOT A SENTENCE. The host translates; the package does not decide
 * which language its user reads in.
 */
final class UploadRejected extends RuntimeException
{
    use RendersAsJson;

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message = ''): self
    {
        return new self($reason, $message !== '' ? $message : $reason);
    }

    protected function status(): int
    {
        return 422;
    }
}
