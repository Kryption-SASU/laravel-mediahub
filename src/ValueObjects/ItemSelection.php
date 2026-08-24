<?php

declare(strict_types=1);

namespace Kryption\MediaHub\ValueObjects;

/**
 * WHAT A BATCH OPERATION ACTS ON — KEYS, not objects yet.
 *
 * ⚠️ MEDIA AND FOLDERS ARE TWO SEPARATE LISTS, and that is not fussiness. The original module
 * received a flat list of identifiers accompanied by an `is_folder` flag supplied by the
 * CLIENT: it was the client that decided which table would be searched, and therefore which
 * check applied. Two typed lists close the question.
 *
 * ⚠️ AND THESE ARE ROUTE KEYS, NOT DATABASE KEYS. A sequential identifier can be enumerated.
 */
final class ItemSelection
{
    /**
     * @param  array<int, string>  $media
     * @param  array<int, string>  $folders
     */
    public function __construct(
        public readonly array $media = [],
        public readonly array $folders = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->media === [] && $this->folders === [];
    }

    public function count(): int
    {
        return count($this->media) + count($this->folders);
    }
}
