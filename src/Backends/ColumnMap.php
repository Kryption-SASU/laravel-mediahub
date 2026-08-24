<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Backends;

use Kryption\MediaHub\Exceptions\StorageMisconfigured;

/**
 * THE MAPPING BETWEEN THE PACKAGE'S NAMES AND THOSE OF THE HOST'S TABLE.
 *
 * ⚠️ THREE CASES, NOT TWO. A logical column may carry the SAME name, carry ANOTHER name, or not
 * exist at all. It is the third that decides the architecture: on the real schema serving as
 * the target, `uuid`, `checksum`, `type`, `extension`, `file_name`, `width`, `height` and
 * `duration` are all missing. An adapter that could only rename would produce queries against
 * absent columns — that is, an SQL error on the first search.
 *
 * ⚠️ AND AN ABSENT COLUMN IS ASKED FOR EXPLICITLY. `physical()` returns `null`, `require()`
 * raises. Code that needs a column in order to filter must go through `require()`: better a
 * clean failure in development than a `WHERE checksum = …` against a table without that column
 * — the engine's error message will never say "your schema is poorer than expected".
 *
 * ⚠️ SOME ABSENCES ARE FILLED BY DERIVATION, NOT BY QUERY. The file name and the extension are
 * read out of the path, the family is deduced from the MIME type. Those are READ values: they
 * can be displayed, they cannot be sorted or filtered on without being written. The distinction
 * is exactly the one between an accessor and an index.
 */
final class ColumnMap
{
    /**
     * @param  array<string, string|null>  $map  logical => physical, or `null` if absent
     * @param  mixed  $rootValue  what "no parent" is worth in this schema: `null`, or `0`
     */
    private function __construct(
        private readonly array $map,
        private readonly mixed $rootValue,
        private readonly string $routeKey,
    ) {
    }

    /**
     * @param  array<string, string|null>  $map
     */
    public static function of(array $map, mixed $rootValue = null, string $routeKey = 'uuid'): self
    {
        return new self($map, $rootValue, $routeKey);
    }

    /**
     * ⚠️ WITHOUT A DECLARED MAPPING, THE LOGICAL NAME IS THE PHYSICAL NAME. That is what makes
     * standalone mode free: it has no map to write, and there are not two code paths depending
     * on the mode.
     */
    public function physical(string $logical): ?string
    {
        if (! array_key_exists($logical, $this->map)) {
            return $logical;
        }

        return $this->map[$logical];
    }

    public function has(string $logical): bool
    {
        return $this->physical($logical) !== null;
    }

    public function require(string $logical): string
    {
        $physical = $this->physical($logical);

        if ($physical === null) {
            throw StorageMisconfigured::because('column_absent_in_host_schema: '.$logical);
        }

        return $physical;
    }

    /**
     * THE MAPPINGS ACTUALLY DECLARED — without the ones pointing at nothing.
     *
     * ⚠️ IT EXISTS TO CONFRONT THE MAP WITH THE SCHEMA. A physical column named here and absent
     * from the table would only show itself on the first query touching it, on an installation
     * no bench has to hand.
     *
     * @return array<string, string>
     */
    public function physicalColumns(): array
    {
        $declared = [];

        foreach ($this->map as $logical => $physical) {
            if ($physical !== null) {
                $declared[$logical] = $physical;
            }
        }

        return $declared;
    }

    /** The logical name matching a physical column, if there is one. */
    public function logical(string $physical): ?string
    {
        foreach ($this->map as $logicalName => $physicalName) {
            if ($physicalName === $physical) {
                return $logicalName;
            }
        }

        return $physical;
    }

    /**
     * ⚠️ "NO PARENT" IS NOT WRITTEN THE SAME WAY EVERYWHERE. The canonical schema uses `null`
     * and a foreign key; the one serving as the target uses `0` and no constraint. A
     * `whereNull('folder_id')` query returns zero rows there — that is, an empty library,
     * without the slightest error.
     */
    public function rootValue(): mixed
    {
        return $this->rootValue;
    }

    public function routeKey(): string
    {
        return $this->routeKey;
    }
}
