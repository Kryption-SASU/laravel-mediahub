<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * SOMEBODY SIGNED IN, AND NOTHING MORE.
 *
 * ⚠️ IT IS NEVER SAVED. `actingAs()` puts an object on the guard; the package only ever asks it
 * for its identifier. Persisting one would drag the host's `users` table — and its columns, its
 * constraints and its own migrations — into a bench about who owns a file.
 */
final class LegacyUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
