<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Kryption\MediaHub\Contracts\MediaScope;

/**
 * THE SCOPING, PLACED AT THE LOWEST POSSIBLE LEVEL.
 *
 * ⚠️ AS A GLOBAL SCOPE, AND THAT IS THE WHOLE POINT. Scoping written into every query is
 * scoping that will be forgotten: one new screen, one maintenance command or one switch branch
 * is enough for a customer to see another's files. The module this package replaces filtered
 * its LISTINGS correctly — and not its ACTIONS: a guessed identifier was enough to delete
 * another customer's file.
 *
 * ⚠️ AND THE KEY IS SET ON CREATION, not merely read on retrieval. Without that, a media
 * recorded through a door with no context stays without a scope — therefore invisible to its
 * own customer, absent from their export, and refused for download forever.
 *
 * ⚠️ IT CAN BE REMOVED, BUT IT HAS TO BE SAID: `withoutMediaScope()` exists for maintenance
 * commands that walk everything. A named way out is better than scoping worked around by
 * writing the query by hand.
 */
trait ScopedToMediaScope
{
    public static function bootScopedToMediaScope(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                app(MediaScope::class)->constrain($builder);
            }
        });

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('scope_key') === null) {
                $model->setAttribute('scope_key', app(MediaScope::class)->currentKey());
            }
        });
    }

    /** Without scoping — for maintenance, and saying so. */
    public function scopeWithoutMediaScope(Builder $query): Builder
    {
        return $query->withoutGlobalScopes();
    }
}
