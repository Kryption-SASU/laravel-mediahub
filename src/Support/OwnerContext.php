<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\MediaOwner;

/**
 * TURNING "WHO IS ACTING" INTO SOMETHING AN ACTION WILL ACCEPT.
 *
 * ⚠️ IT LIVES IN ONE PLACE BECAUSE THE TWO CALLERS MUST NOT DIVERGE. Uploading a file and
 * creating a folder both write an owner into the same schema; two copies of this three-line
 * decision is how one of them ends up writing a column the other has learned not to.
 */
final class OwnerContext
{
    /**
     * @param  class-string  $model  The model that will be written — it knows its own columns.
     * @return array<string, int|string>
     */
    public static function for(string $model, MediaOwner $owner): array
    {
        $key = $owner->currentKey();

        /* ⚠️ NOBODY ACTING MEANS NOTHING WRITTEN, not a zero. A queue worker or a console
         * command has no user, and inventing one attributes files to somebody who never
         * touched them — which is worse than an empty column, because it looks like a fact. */
        if ($key === null) {
            return [];
        }

        $context = ['owner_id' => $key];

        $type = $owner->currentType();

        /*
         * ⚠️ THE TYPE ONLY WHERE THERE IS SOMEWHERE TO PUT IT. The adopted tables carry a plain
         * `user_id` and no type column at all; writing one would name a column that does not
         * exist, and the first upload would fail on SQL rather than on a rule.
         */
        if ($type !== null && $model::hasColumn('owner_type')) {
            $context['owner_type'] = $type;
        }

        return $context;
    }
}
