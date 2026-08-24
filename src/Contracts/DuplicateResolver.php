<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Kryption\MediaHub\Enums\DuplicateDecision;

/**
 * WHAT TO DO WITH A FILE THAT IS ALREADY THERE, BYTE FOR BYTE.
 *
 * ⚠️ THE QUESTION IS ASKED ON THE CONTENT CHECKSUM, not on the name: two files with the same
 * name may differ, and two differently named files be identical.
 */
interface DuplicateResolver
{
    public function resolve(string $checksum, ?string $scopeKey): DuplicateDecision;
}
