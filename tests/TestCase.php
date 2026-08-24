<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests;

use Orchestra\Testbench\TestCase as Testbench;
use Kryption\MediaHub\MediaHubServiceProvider;

/**
 * THE BENCH — a minimal Laravel application, assembled for the package alone.
 *
 * ⚠️ THERE IS NO HOST APPLICATION HERE, and that is the property that counts: what passes on
 * this bench installs anywhere. The module this package replaces had no tests, and could not
 * have any: it assumed a session, a `users` table, a named disk and views that had to exist in
 * whoever installed it.
 */
abstract class TestCase extends Testbench
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [MediaHubServiceProvider::class];
    }
}
