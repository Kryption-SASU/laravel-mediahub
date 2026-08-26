<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Kryption\MediaHub\Support\HiddenPaths;

/**
 * ROWS WHOSE PATH THE HOST HAS DECLARED PRIVATE ARE NOT THERE.
 *
 * ⚠️ A GLOBAL SCOPE, AND THAT IS THE WHOLE POINT. Filtering the listing alone would leave a file
 * that is merely unadvertised: absent from the browser, still returned by its identifier, still
 * downloadable by anyone who guesses one. Hidden has to mean hidden from every door the package
 * opens — the listing, a search, a folder's contents, a lookup, the archive.
 *
 * ⚠️ IT COSTS NOTHING WHERE NOTHING IS CONFIGURED. With an empty list the scope adds no
 * condition at all, so a host that has never heard of this behaves exactly as before.
 *
 * ⚠️ AND IT DOES NOT REPLACE THE APPLICATION'S OWN ACCESS RULES. This says which rows are not
 * part of the library; who may see a row that IS part of it remains {@see AccessPolicy}. A host
 * that confuses the two ends up with one of them doing nothing.
 */
trait ConcealsHiddenPaths
{
    public static function bootConcealsHiddenPaths(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                /* ⚠️ THE PHYSICAL COLUMN, QUALIFIED. The library's path lives under a name the
                 * host chooses, and a query that joins anything at all needs to say which table
                 * it means. */
                $column = $model->getTable().'.'.$model::column('path');

                app(HiddenPaths::class)->apply($builder, $column);
            }
        });
    }
}
