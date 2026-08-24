<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * HOW MUCH ROOM, AND HOW MUCH IS TAKEN.
 *
 * ⚠️ THE QUOTA APPLIES TO THE SCOPE, AND IT IS SUPPLIED BY THE HOST. The original module added
 * a column to the users table of the application installing it, then measured per PERSON in a
 * product that partitions per ORGANISATION: the two measurements were not talking about the
 * same object.
 *
 * ⚠️ AND IT IS CHECKED BEFORE THE WRITE, never after.
 */
interface QuotaPolicy
{
    /** The scope's limit, in bytes. `null`: unlimited. */
    public function limitInBytes(?string $scopeKey): ?int;

    /** The scope's usage, in bytes. */
    public function usedInBytes(?string $scopeKey): int;

    /** Does this upload fit in what is left? */
    public function allows(?string $scopeKey, int $incomingBytes): bool;
}
