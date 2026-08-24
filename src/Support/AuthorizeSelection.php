<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * AUTHORISING A BATCH — every item, and before touching a single one.
 *
 * ⚠️ THE WHOLE BATCH IS EXAMINED BEFORE THE FIRST WRITE. Authorising as you go — check the
 * item, act, move to the next — leaves the batch half executed on the first refusal, that is,
 * in a state nobody asked for and nothing describes. That is the original module's flaw: it
 * authorised nine items out of ten and executed anyway.
 *
 * ⚠️ AND THE REFUSAL DOES NOT SAY WHICH ONE. Naming the offending item would teach anyone
 * trying identifiers which ones exist — the same disclosure as telling "not found" apart from
 * "not yours".
 */
final class AuthorizeSelection
{
    public function __construct(private readonly AccessPolicy $policy)
    {
    }

    /**
     * @throws AuthorizationException
     */
    public function modify(ResolvedItems $items): void
    {
        $this->each($items, fn ($item): bool => $this->policy->modify($item));
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(ResolvedItems $items): void
    {
        $this->each($items, fn ($item): bool => $this->policy->destroy($item));
    }

    private function each(ResolvedItems $items, callable $allows): void
    {
        foreach ($items->media as $media) {
            if (! $allows($media)) {
                throw new AuthorizationException();
            }
        }

        foreach ($items->folders as $folder) {
            if (! $allows($folder)) {
                throw new AuthorizationException();
            }
        }
    }
}
