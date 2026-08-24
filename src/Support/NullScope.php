<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Contracts\MediaScope;

/**
 * THE DEFAULT SCOPE: there is none.
 *
 * ⚠️ THAT IS WHAT MAKES THE PACKAGE USABLE WITHOUT CONFIGURING ANYTHING. A single-tenant
 * product should not have to declare a partitioning it has no use for.
 */
final class NullScope implements MediaScope
{
    public function currentKey(): ?string
    {
        return null;
    }

    public function constrain(Builder $query): Builder
    {
        return $query;
    }
}
