<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Contracts\DiskResolver;
use Kryption\MediaHub\Contracts\DuplicateResolver;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Contracts\MediaTypeResolver;
use Kryption\MediaHub\Contracts\QuotaPolicy;
use Kryption\MediaHub\Support\NullScope;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE PACKAGE'S PROMISE: `composer require` is enough.
 *
 * ⚠️ THIS FILE CHECKS THAT NO CONFIGURATION IS REQUIRED TO START. A package that has to be
 * assembled before it answers is not installable.
 */
class ServiceProviderTest extends TestCase
{
    public function test_every_contract_has_a_default_implementation(): void
    {
        foreach ([MediaScope::class, DiskResolver::class, QuotaPolicy::class, MediaTypeResolver::class, DuplicateResolver::class] as $contract) {
            $this->assertTrue($this->app->bound($contract), $contract.' has no binding.');
            $this->assertInstanceOf($contract, $this->app->make($contract));
        }
    }

    public function test_the_configuration_is_merged(): void
    {
        $this->assertIsArray(config('mediahub'));
        $this->assertSame('standalone', config('mediahub.backend.driver'));
    }

    /** The default scope partitions nothing, and breaks no query. */
    public function test_the_default_scope_lets_everything_through(): void
    {
        $scope = $this->app->make(MediaScope::class);

        $this->assertInstanceOf(NullScope::class, $scope);
        $this->assertNull($scope->currentKey());

        /*
         * ⚠️ A REAL QUERY BUILDER, NOT A DOUBLE. `Builder` goes through magic methods: doubling
         * it makes PHPUnit say it cannot imitate everything, and the test would end up measuring
         * the double rather than the code.
         */
        $query = (new \Illuminate\Database\Eloquent\Builder(
            $this->app['db']->connection()->query()
        ));

        $this->assertSame($query, $scope->constrain($query));
    }

    /**
     * ⚠️ THE HOST'S BINDING WINS. Without this guard, the boot order of service providers would
     * decide who prevails — and that order changes without warning.
     */
    public function test_a_host_binding_is_never_overwritten(): void
    {
        $theirs = new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return 'org:42';
            }

            public function constrain(Builder $query): Builder
            {
                return $query;
            }
        };

        $this->app->singleton(MediaScope::class, static fn () => $theirs);

        $this->app->register(\Kryption\MediaHub\MediaHubServiceProvider::class, true);

        $this->assertSame('org:42', $this->app->make(MediaScope::class)->currentKey());
    }

    public function test_the_configuration_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'mediahub-config'])->assertExitCode(0);

        $this->assertFileExists(config_path('mediahub.php'));

        @unlink(config_path('mediahub.php'));
    }
}
