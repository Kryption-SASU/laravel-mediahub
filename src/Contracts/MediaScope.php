<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * THE SCOPE — what partitions the library.
 *
 * ⚠️ IT IS AN OPAQUE STRING, and the package knows nothing else about it: `org:42`, `team:7`,
 * `workspace:abc`. No foreign key to a model it does not know.
 *
 * ⚠️ `null` IS A VALID ANSWER, not an error: a single-tenant product has no scope, and even
 * elsewhere some media have none.
 *
 * ⚠️ AND `constrain()` APPLIES AT THE LOWEST LEVEL. Scoping placed in each branch of a switch
 * is scoping that will be forgotten: this one goes all the way down to the query, where it can
 * no longer be worked around.
 */
interface MediaScope
{
    /** The scope to stamp on whatever is being recorded now. */
    public function currentKey(): ?string;

    /** Bounds a query to the current scope. */
    public function constrain(Builder $query): Builder;
}
