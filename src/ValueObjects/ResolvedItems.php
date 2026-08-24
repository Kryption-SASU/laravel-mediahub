<?php

declare(strict_types=1);

namespace Kryption\MediaHub\ValueObjects;

use Illuminate\Support\Collection;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * THE SELECTION, ONCE RESOLVED — that is, once it has been through the global scope.
 *
 * ⚠️ EVERYTHING IN HERE BELONGS TO WHOEVER IS ASKING. That is the only thing this type
 * guarantees, and it is the reason destructive actions accept nothing else: an action taking a
 * list of raw keys would reopen the scoping leak for the first distracted caller.
 */
final class ResolvedItems
{
    /**
     * @param  Collection<int, Media>  $media
     * @param  Collection<int, MediaFolder>  $folders
     */
    public function __construct(
        public readonly Collection $media,
        public readonly Collection $folders,
    ) {
    }

    public static function empty(): self
    {
        return new self(new Collection(), new Collection());
    }

    public function isEmpty(): bool
    {
        return $this->media->isEmpty() && $this->folders->isEmpty();
    }

    public function count(): int
    {
        return $this->media->count() + $this->folders->count();
    }
}
