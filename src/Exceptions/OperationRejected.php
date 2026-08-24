<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;
use Kryption\MediaHub\Exceptions\Concerns\RendersAsJson;

/**
 * THE OPERATION IS REFUSED, AND WE SAY WHY.
 *
 * ⚠️ THE REASON IS A KEY, NOT A SENTENCE. The host translates; the package does not decide
 * which language its user reads in. The original module returned a hardcoded
 * `'Invalid item selected!'` — a string none of its non-English installers could ever
 * translate without modifying it.
 *
 * ⚠️ AND IT IS DISTINCT FROM `ItemNotFound`, which is not the same HTTP response. A business
 * refusal is fixed by changing the request; a missing object is not.
 */
final class OperationRejected extends RuntimeException
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
