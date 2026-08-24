<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * EMPTYING THE TRASH — of the current scope, and of it alone.
 *
 * ⚠️ "EMPTY THE TRASH" IS NOT A MAINTENANCE OPERATION. It is triggered by a person, from a
 * screen, and it must touch only what that person sees. The global scope takes care of it: it
 * is precisely for this kind of action, written a year after the rest, that the scope sits on
 * the model rather than in each query.
 */
final class EmptyTrash
{
    public function __construct(private readonly ForceDeleteItems $purge)
    {
    }

    public function __invoke(): ResolvedItems
    {
        return ($this->purge)(new ResolvedItems(
            Media::onlyTrashed()->get(),
            MediaFolder::onlyTrashed()->get(),
        ));
    }
}
