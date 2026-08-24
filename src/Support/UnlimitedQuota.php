<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\QuotaPolicy;

/**
 * NO LIMIT — the default.
 *
 * ⚠️ AND IT ANSWERS `null`, NOT ZERO. Zero would be a limit reached: an unknown quota and an
 * exhausted quota are not said the same way.
 */
final class UnlimitedQuota implements QuotaPolicy
{
    public function limitInBytes(?string $scopeKey): ?int
    {
        return null;
    }

    public function usedInBytes(?string $scopeKey): int
    {
        return 0;
    }

    public function allows(?string $scopeKey, int $incomingBytes): bool
    {
        return true;
    }
}
