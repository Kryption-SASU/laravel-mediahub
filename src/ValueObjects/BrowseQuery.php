<?php

declare(strict_types=1);

namespace Kryption\MediaHub\ValueObjects;

use Kryption\MediaHub\Models\MediaFolder;

/**
 * WHAT IS BEING ASKED FOR — and nothing else the client might want on top.
 *
 * ⚠️ THE DEFAULT PAGE IS 1, AND THAT IS A REAL BUG WE REFUSE TO REPEAT. The original module
 * used the NUMBER OF ITEMS PER PAGE as its default page: with 30 per page, a call without
 * parameters started at page 30, that is, after 870 items. On the reference estate that skipped
 * 1,560 files and returned an empty list to anyone opening the screen without clicking
 * anywhere.
 *
 * ⚠️ SORTING IS AN ALLOW-LIST, NOT A COLUMN. A column name received from the client and dropped
 * into an `ORDER BY` allows sorting on `checksum` — which, page after page, reveals the
 * checksums of files we are not allowed to read. And on some engines, the clause accepts more
 * than a column name.
 *
 * ⚠️ THE PAGE SIZE IS CAPPED. Without a cap, `per_page=100000` is a one-parameter attack: it
 * requires no permission and loads the entire table into memory.
 */
final class BrowseQuery
{
    /** The only sorts available — public key to real column. */
    public const SORTS = [
        'name' => 'name',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'size' => 'size',
    ];

    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  MediaFolder|null  $folder  the folder being looked at; `null` with `$rootOnly` = the root
     * @param  array<int, string>  $types  families kept, empty = all of them
     */
    public function __construct(
        public readonly ?MediaFolder $folder = null,
        public readonly bool $rootOnly = false,
        public readonly ?string $search = null,
        public readonly array $types = [],
        public readonly bool $trashed = false,
        public readonly string $sort = 'created_at',
        public readonly bool $descending = true,
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * ⚠️ ALL THE NORMALISATION IS HERE, AND IT IS THE ONLY DOOR. A controller building the
     * object by hand from the raw request values would bypass the cap and the allow-list with
     * nothing reporting it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, ?MediaFolder $folder = null, bool $rootOnly = false): self
    {
        $sort = (string) ($input['sort'] ?? 'created_at');

        return new self(
            folder: $folder,
            rootOnly: $rootOnly,
            search: self::term($input['search'] ?? null),
            types: self::families($input['types'] ?? []),
            trashed: (bool) ($input['trashed'] ?? false),
            sort: array_key_exists($sort, self::SORTS) ? $sort : 'created_at',
            descending: strtolower((string) ($input['direction'] ?? 'desc')) !== 'asc',
            page: max(1, (int) ($input['page'] ?? 1)),
            perPage: min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE))),
        );
    }

    public function column(): string
    {
        return self::SORTS[$this->sort] ?? 'created_at';
    }

    /**
     * ⚠️ WILDCARDS ARE STRIPPED FROM THE TERM, AND THE PATTERN IS BUILT HERE. Without that,
     * searching for `%` returns the entire library — one parameter is enough to bypass
     * pagination.
     *
     * ⚠️ AND THEY ARE STRIPPED RATHER THAN ESCAPED, FOR WANT OF BETTER. Escaping a `LIKE`
     * requires an `ESCAPE` clause Laravel does not emit, and whose default differs from one
     * engine to the next: MySQL takes the backslash, SQLite has none. Adding it would require a
     * raw SQL expression, which this package forbids itself. Consequence accepted and written
     * down: searching for `read_me` will not find the underscore itself.
     */
    private static function term(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $term = trim(str_replace(['%', '_', '\\'], '', $raw));

        return $term === '' ? null : $term;
    }

    /**
     * @return array<int, string>
     */
    private static function families(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);

        $families = [];

        foreach ($raw as $family) {
            $family = strtolower(trim((string) $family));

            if ($family !== '' && ! in_array($family, $families, true)) {
                $families[] = $family;
            }
        }

        return $families;
    }
}
