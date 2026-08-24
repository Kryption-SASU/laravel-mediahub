<?php

declare(strict_types=1);

namespace Kryption\MediaHub;

use Illuminate\Support\ServiceProvider;
use Kryption\MediaHub\Support\Remote\GuardedRemoteFetcher;
use Kryption\MediaHub\Support\Remote\CurlTransport;
use Kryption\MediaHub\Support\Remote\AddressGuard;
use Kryption\MediaHub\Contracts\RemoteFetcher;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Contracts\DiskResolver;
use Kryption\MediaHub\Contracts\FileNamer;
use Kryption\MediaHub\Contracts\PathGenerator;
use Kryption\MediaHub\Contracts\UploadValidator;
use Kryption\MediaHub\Contracts\UrlGenerator;
use Kryption\MediaHub\Contracts\DuplicateResolver;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Contracts\MediaTypeResolver;
use Kryption\MediaHub\Contracts\QuotaPolicy;
use Kryption\MediaHub\Support\Conversions\GdConversionDriver;
use Kryption\MediaHub\Support\Conversions\ImagickConversionDriver;
use Kryption\MediaHub\Support\Conversions\NullConversionDriver;
use Kryption\MediaHub\Support\DeepUploadValidator;
use Kryption\MediaHub\Support\DefaultPathGenerator;
use Kryption\MediaHub\Support\MimeMediaTypeResolver;
use Kryption\MediaHub\Support\NullScope;
use Kryption\MediaHub\Support\ReuseDuplicates;
use Kryption\MediaHub\Support\SingleDiskResolver;
use Kryption\MediaHub\Support\ScopeIsTheBoundary;
use Kryption\MediaHub\Support\SignedUrlGenerator;
use Kryption\MediaHub\Support\SluggedFileNamer;
use Kryption\MediaHub\Support\StorageDisk;
use Kryption\MediaHub\Support\UnlimitedQuota;

/**
 * THE ENTRY POINT OF THE PACKAGE.
 *
 * ⚠️ IT ONLY DOES WHAT A PACKAGE IS ENTITLED TO DO. The one it replaces set the host
 * application's default string length, reserved two global aliases, dumped thirteen functions
 * into the root namespace and loaded migrations from a folder that did not exist. Every one of
 * those lines made the package a little more impossible to upgrade.
 *
 * ⚠️ EVERY CONTRACT HAS A DEFAULT IMPLEMENTATION USABLE AS IS, and that is what keeps the
 * promise: `composer require` must be enough. A package that has to be configured before it
 * will start is not installable, it is a kit.
 *
 * ⚠️ AND NO BINDING OVERWRITES THE HOST'S. `bind()` is only called when the contract is not
 * already resolved: an application that declared its own scope keeps it, even if the boot
 * order of service providers changes one day.
 */
class MediaHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bootRemoteFetcher();

        $this->mergeConfigFrom(__DIR__.'/../config/mediahub.php', 'mediahub');

        /*
         * ⚠️ THE SCHEMA MAP IS A STATIC CACHE, AND THIS IS WHERE IT IS FLUSHED. It is consulted
         * on every attribute read; rebuilding it each time would be expensive for a value that
         * never changes during a request. A fresh application — therefore a fresh configuration
         * — must start from nothing, otherwise the first bench that switches mode would
         * contaminate every bench after it.
         */
        HostSchema::flush();

        $this->bindDefaults();
    }

    /**
     * FETCHING A FILE FROM A URL, GUARDED.
     *
     * ⚠️ BOUND EVEN WHERE THE FEATURE IS OFF. The switch is read where the fetch is asked for,
     * not here: a container that cannot resolve the contract would fail with "target is not
     * instantiable", which tells whoever reads it nothing about a configuration flag.
     *
     * ⚠️ AND A HOST BINDING THEIR OWN STILL WINS, like every other contract in this package.
     */
    private function bootRemoteFetcher(): void
    {
        $this->app->bind(RemoteFetcher::class, static function ($app): RemoteFetcher {
            $remote = (array) $app['config']->get('mediahub.remote', []);

            $guard = new AddressGuard(
                /* ⚠️ RESOLUTION IS INJECTED so the rules can be tested without a name server. */
                static function (string $host): array {
                    $records = @dns_get_record($host, DNS_A | DNS_AAAA);

                    if ($records === false) {
                        return [];
                    }

                    return array_values(array_filter(array_map(
                        static fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''),
                        $records,
                    )));
                },
                (array) ($remote['schemes'] ?? ['http', 'https']),
                (array) ($remote['ports'] ?? [80, 443]),
                (array) ($remote['hosts'] ?? []),
            );

            return new GuardedRemoteFetcher(
                $guard,
                new CurlTransport((int) ($remote['timeout'] ?? 10)),
                (int) ($remote['max_bytes'] ?? 33_554_432),
                (int) ($remote['max_redirects'] ?? 3),
            );
        });
    }

    public function boot(): void
    {
        $this->bootMigrations();
        $this->bootRoutes();

        /*
         * ⚠️ LOADED, NOT PUBLISHED-AND-REQUIRED. The package ships default wording so that
         * `composer require` is enough to get readable refusals; a host that wants its own
         * publishes the files and edits them. Requiring publication first would mean every
         * fresh install renders raw keys at its users until someone remembers a command.
         */
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'mediahub');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mediahub.php' => $this->app->configPath('mediahub.php'),
            ], 'mediahub-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'mediahub-migrations');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/mediahub'),
            ], 'mediahub-lang');

            /*
             * ⚠️ THE STANDALONE BUNDLE, AND IT IS ABSENT OUTSIDE A RELEASE. It is built when a
             * version is tagged and committed with it, so a host following a moving branch has
             * no `dist/` at all. Declaring the publication anyway is deliberate: the command
             * then reports that it copied nothing, which is a far better answer than an unknown
             * publish tag for somebody wondering where their screen went.
             */
            $this->publishes([
                __DIR__.'/../dist' => $this->app->publicPath('vendor/mediahub'),
            ], 'mediahub-assets');
        }
    }

    /**
     * MIGRATIONS ONLY LOAD IN STANDALONE MODE.
     *
     * ⚠️ AND THAT IS THE WHOLE POINT OF `table` MODE. A host plugging the package onto existing
     * tables does not want ours: creating them anyway would give it two schemas for the same
     * thing, one of them empty — and it would find out about the duplicate the day it went
     * looking for why its screen shows nothing.
     *
     * ⚠️ THEY REMAIN PUBLISHABLE IN EVERY CASE. A host that wants to read them, edit them or
     * run them by hand is entitled to; it is the automatic loading that depends on the mode,
     * not the availability.
     */
    private function bootMigrations(): void
    {
        if ($this->app['config']->get('mediahub.backend.driver') === 'standalone') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            return;
        }

        /*
         * ⚠️ AN ADOPTED SCHEMA STILL RECEIVES WHAT IT LACKS. The host's tables are left alone,
         * but a legacy schema has neither a conversions table nor a linking table: without
         * them half the package cannot work, and refusing to add them would amount to adopting
         * nothing at all. They are ADDED, alongside, and do nothing if they already exist.
         */
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations-adopted');
    }

    /**
     * ROUTES — two possible doors, the same file.
     *
     * ⚠️ THE SAME FILE IS LOADED INTO TWO GROUPS, AND THAT IS DELIBERATE. Writing the same
     * routes twice, once for the browser and once for the API, is a guarantee that they will
     * diverge: the fix applied on one side will be missing on the other, and the door nobody
     * watches is the one that stays open. Only the prefix, the domain, the middleware and the
     * NAME prefix differ.
     *
     * ⚠️ HENCE TWO DISTINCT NAME PREFIXES. Without them the second registration would silently
     * overwrite the first in the name table, and URL generation would point at the wrong door.
     *
     * ⚠️ AND NOTHING IS REGISTERED IF THE HOST DOES NOT WANT IT. A product that exposes the
     * library only to its own code turns both off — in which case building a signed URL raises,
     * rather than silently handing back a public one.
     */
    private function bootRoutes(): void
    {
        $config = $this->app['config'];
        $router = $this->app['router'];

        if ((bool) $config->get('mediahub.routes.enabled', true)) {
            $router->group([
                'prefix' => (string) $config->get('mediahub.routes.prefix', 'media'),
                'middleware' => (array) $config->get('mediahub.routes.middleware', ['web']),
                'domain' => $config->get('mediahub.routes.domain'),
                'as' => (string) $config->get('mediahub.routes.as', 'mediahub.'),
            ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/mediahub.php'));
        }

        if ((bool) $config->get('mediahub.api.enabled', false)) {
            $router->group([
                'prefix' => (string) $config->get('mediahub.api.prefix', 'api/media'),
                'middleware' => (array) $config->get('mediahub.api.middleware', ['api']),
                'domain' => $config->get('mediahub.api.domain'),
                'as' => (string) $config->get('mediahub.api.as', 'mediahub.api.'),
            ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/mediahub.php'));
        }
    }

    /**
     * THE DEFAULTS — each one chosen so the package starts without demanding anything.
     *
     * ⚠️ THEY ARE DELIBERATELY MODEST: no scope, no limit, a single disk. A default that
     * guesses on the host's behalf ends up being silently wrong — which is exactly what reading
     * the scope out of the session used to do.
     */
    private function bindDefaults(): void
    {
        $this->bindIfAbsent(MediaScope::class, static fn (): MediaScope => new NullScope());

        /*
         * ⚠️ THE DISK IS RESOLVED ONCE, AT BOOT. In `path` mode this resolution DECLARES the
         * disk: doing it lazily would leave a window in which another provider, or a command,
         * would ask for a disk that does not exist yet.
         */
        $this->bindIfAbsent(DiskResolver::class, fn (): DiskResolver => new SingleDiskResolver(
            (new StorageDisk($this->app['config']))->resolve($this->app->publicPath())
        ));

        $this->bindIfAbsent(QuotaPolicy::class, static fn (): QuotaPolicy => new UnlimitedQuota());

        $this->bindIfAbsent(MediaTypeResolver::class, static fn (): MediaTypeResolver => new MimeMediaTypeResolver());

        $this->bindIfAbsent(DuplicateResolver::class, static fn (): DuplicateResolver => new ReuseDuplicates());

        $this->bindIfAbsent(PathGenerator::class, static fn (): PathGenerator => new DefaultPathGenerator());

        $this->bindIfAbsent(FileNamer::class, fn (): FileNamer => new SluggedFileNamer(
            $this->app['filesystem']
        ));

        $this->bindIfAbsent(UploadValidator::class, fn (): UploadValidator => new DeepUploadValidator(
            $this->app['config']
        ));

        $this->bindIfAbsent(ConversionDriver::class, fn (): ConversionDriver => $this->imageDriver());

        $this->bindIfAbsent(UrlGenerator::class, fn (): UrlGenerator => $this->urlGenerator());

        /*
         * ⚠️ THE DEFAULT ALLOWS EVERYTHING, AND THAT IS NOT AN OPEN DOOR. The scope already
         * decides what EXISTS for the caller, and the routes live inside the host's middleware
         * group. This policy is the FINE mesh on top of that: a default that refused everything
         * would make the package unusable without configuration.
         */
        $this->bindIfAbsent(AccessPolicy::class, function (): AccessPolicy {
            $chosen = $this->app['config']->get('mediahub.context.policy');

            return is_string($chosen) && $chosen !== ''
                ? $this->app->make($chosen)
                : new ScopeIsTheBoundary();
        });
    }

    /**
     * ⚠️ A CLASS NAME IN THE CONFIGURATION WINS, but there is NO fallback if that name is
     * wrong. A typo that fell back to the signed generator would produce URLs different from
     * the ones the host expects, with no message at all: better that the application refuses
     * to start.
     */
    private function urlGenerator(): UrlGenerator
    {
        $chosen = $this->app['config']->get('mediahub.urls.generator');

        if (is_string($chosen) && $chosen !== '') {
            return $this->app->make($chosen);
        }

        return new SignedUrlGenerator(
            $this->app['filesystem'],
            $this->app['config'],
            $this->app['url'],
        );
    }

    /**
     * THE IMAGE DRIVER, CHOSEN BY CONFIGURATION.
     *
     * ⚠️ WE DO NOT SWITCH TO ANOTHER DRIVER ON OUR OWN when the requested one has no extension
     * behind it. A silent fallback would produce different thumbnails depending on the machine
     * — and nobody would know why staging and production do not render the same image. The
     * requested driver answers "I cannot", and that is visible.
     *
     * ⚠️ AN UNKNOWN NAME, ON THE OTHER HAND, DOES NOT BRING THE APPLICATION DOWN: we fall back
     * to the driver that can do nothing. A typo in a configuration file must not stop files
     * that are actually there from being served.
     */
    private function imageDriver(): ConversionDriver
    {
        $filesystems = $this->app['filesystem'];

        return match ((string) $this->app['config']->get('mediahub.images.driver', 'gd')) {
            'gd' => new GdConversionDriver($filesystems),
            'imagick' => new ImagickConversionDriver($filesystems, $this->app['config']),
            default => new NullConversionDriver(),
        };
    }

    /**
     * ⚠️ THE HOST'S BINDING ALWAYS WINS. Without this guard, the boot order of service
     * providers would decide who prevails — and that order changes without warning.
     */
    private function bindIfAbsent(string $abstract, callable $factory): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->singleton($abstract, $factory);
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            MediaScope::class,
            DiskResolver::class,
            QuotaPolicy::class,
            MediaTypeResolver::class,
            DuplicateResolver::class,
            PathGenerator::class,
            FileNamer::class,
            UploadValidator::class,
            ConversionDriver::class,
            UrlGenerator::class,
            AccessPolicy::class,
        ];
    }
}
