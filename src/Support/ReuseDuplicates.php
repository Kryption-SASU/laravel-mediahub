<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\DuplicateResolver;
use Kryption\MediaHub\Enums\DuplicateDecision;

/**
 * A DUPLICATE IS REUSED — one more row, the same object.
 *
 * ⚠️ AND DELETION MUST THEN COUNT REFERENCES: erasing the bytes because one row disappears
 * would break all the others. The original module had this defect without knowing it — four
 * avatar rows there named the same object.
 */
final class ReuseDuplicates implements DuplicateResolver
{
    public function resolve(string $checksum, ?string $scopeKey): DuplicateDecision
    {
        return DuplicateDecision::Reuse;
    }
}
