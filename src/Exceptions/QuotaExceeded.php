<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;
use Kryption\MediaHub\Exceptions\Concerns\RendersAsJson;

/** The scope has no room left. Checked BEFORE the write, never after. */
final class QuotaExceeded extends RuntimeException
{
    use RendersAsJson;

    public function __construct(public readonly ?string $scopeKey, public readonly int $incomingBytes)
    {
        parent::__construct('quota_exceeded');
    }

    protected function status(): int
    {
        return 413;
    }

    protected function reasonKey(): string
    {
        return 'quota_exceeded';
    }
}
