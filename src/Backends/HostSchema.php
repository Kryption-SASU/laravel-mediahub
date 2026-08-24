<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Backends;

use Kryption\MediaHub\Exceptions\StorageMisconfigured;

/**
 * THE HOST'S SCHEMA, READ ONCE AND SHARED.
 *
 * ⚠️ THREE LAYERS, IN THIS ORDER: the package defaults, then a shipped PRESET, then whatever
 * the host writes. Without the preset, plugging the package onto a known schema would mean
 * copying forty mapping lines into the configuration of every installation — and the fortieth
 * would be wrong. Without the override, a schema that has drifted by a hair could not be
 * plugged in at all.
 *
 * ⚠️ AND IT IS A STATIC CACHE, FLUSHED ON EVERY APPLICATION BOOT. The mapping is consulted on
 * every attribute read: rebuilding it each time would be expensive for a value that never
 * changes during a request. The service provider flushes it in `register()`, so once per
 * application — which is enough, and which keeps the benches honest, since they build one
 * application per test.
 */
final class HostSchema
{
    private static ?array $resolved = null;

    private static ?ColumnMap $media = null;

    private static ?ColumnMap $folders = null;

    public static function flush(): void
    {
        self::$resolved = null;
        self::$media = null;
        self::$folders = null;
    }

    public static function forMedia(): ColumnMap
    {
        return self::$media ??= ColumnMap::of(
            self::config()['map']['files'] ?? [],
            self::config()['root_folder'] ?? null,
            (string) (self::config()['route_key'] ?? 'uuid'),
        );
    }

    public static function forFolders(): ColumnMap
    {
        return self::$folders ??= ColumnMap::of(
            self::config()['map']['folders'] ?? [],
            self::config()['root_folder'] ?? null,
            (string) (self::config()['route_key'] ?? 'uuid'),
        );
    }

    /**
     * THE TABLE NAME — prefixed in standalone mode, named outright when adopting a schema.
     *
     * ⚠️ SOME TABLES MAY NOT EXIST, and `null` is then an answer. The target schema has neither
     * a conversions table nor a linking table: inventing them under a prefix would give two
     * sources of truth for the same thumbnail, one of which the old screen does not read.
     */
    public static function table(string $logical): string
    {
        $name = self::tableOrNull($logical);

        if ($name === null) {
            throw StorageMisconfigured::because('table_absent_in_host_schema: '.$logical);
        }

        return $name;
    }

    public static function tableOrNull(string $logical): ?string
    {
        $config = self::config();

        if (array_key_exists($logical, $config['tables'] ?? [])) {
            $name = $config['tables'][$logical];

            return is_string($name) && $name !== '' ? $name : null;
        }

        return ((string) ($config['table_prefix'] ?? 'mediahub_')).$logical;
    }

    public static function hasTable(string $logical): bool
    {
        return self::tableOrNull($logical) !== null;
    }

    /**
     * A SETTING OF THE ADOPTED SCHEMA — preset included.
     *
     * ⚠️ EVERYTHING THAT READS `mediahub.backend.*` MUST COME THROUGH HERE, and that is a
     * lesson paid for. The conversions mirror read the configuration directly: it therefore
     * never saw the preset, and believed itself disabled on the only schema it exists for.
     * Nothing raised — it simply mirrored nothing.
     *
     * ⚠️ AND THERE IS NO DEFAULT VALUE IN THE ARGUMENT, because there used to be one and no
     * mutation could wake it up: the caller casts the result, and the cast already absorbs the
     * `null`. A parameter no test can catch out is a line to maintain, not a guarantee.
     */
    public static function setting(string $key): mixed
    {
        return self::config()[$key] ?? null;
    }

    /** Visibility as the package names it, from what the database keeps. */
    public static function visibilityOut(mixed $value): mixed
    {
        $table = self::config()['visibility'] ?? [];

        if ($table === []) {
            return $value;
        }

        foreach ($table as $name => $stored) {
            /*
             * ⚠️ LOOSE COMPARISON, AND IT IS DELIBERATE. A `tinyint` comes back as `1` or as
             * `"1"` depending on the driver and the version: a strict comparison would return
             * "private" for a public file on one driver and the opposite on the other.
             */
            if ($stored == $value) {
                return $name;
            }
        }

        return $value;
    }

    /** Visibility as the database expects it, from what the package supplies. */
    public static function visibilityIn(mixed $value): mixed
    {
        $table = self::config()['visibility'] ?? [];

        if ($table === [] || ! is_string($value)) {
            return $value;
        }

        return array_key_exists($value, $table) ? $table[$value] : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $host = (array) config('mediahub.backend', []);

        $preset = self::preset($host['preset'] ?? null);

        /*
         * ⚠️ DEEP MERGE ON `map` AND `tables`, FLAT EVERYWHERE ELSE. A flat merge would mean a
         * host correcting ONE column loses the other thirty-nine from the preset — and it would
         * find out on the first query against a column that does not exist.
         *
         * ⚠️ AND A `null` FROM THE HOST DOES NOT COVER THE PRESET. The shipped configuration
         * file declares `route_key => null` and `root_folder => null` in order to document
         * them; without this filter those two nulls overwrote the preset's values — measured:
         * the route key fell back to `uuid` on a schema that has no such column, and the root
         * fell back to `null` on a schema that writes it as `0`. Two empty libraries, no error.
         * A corollary worth knowing: with a preset, a value cannot be brought BACK to `null` —
         * it has to be declared explicitly.
         */
        $resolved = array_merge($preset, array_filter($host, static fn ($value): bool => $value !== null));

        foreach (['map', 'tables', 'visibility'] as $deep) {
            $resolved[$deep] = array_merge(
                (array) ($preset[$deep] ?? []),
                (array) ($host[$deep] ?? []),
            );
        }

        foreach (['files', 'folders'] as $group) {
            $resolved['map'][$group] = array_merge(
                (array) ($preset['map'][$group] ?? []),
                (array) ($host['map'][$group] ?? []),
            );
        }

        return self::$resolved = $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private static function preset(mixed $name): array
    {
        if (! is_string($name) || $name === '') {
            return [];
        }

        /*
         * ⚠️ THE NAME IS REDUCED TO LETTERS AND DASHES BEFORE TOUCHING THE DISK. It comes from
         * a configuration file today, but this is where a path is built: the day it comes from
         * somewhere else, directory traversal will already be closed.
         */
        $clean = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

        $path = __DIR__.'/../../config/presets/'.$clean.'.php';

        if ($clean === '' || ! is_file($path)) {
            throw StorageMisconfigured::because('unknown_backend_preset: '.$name);
        }

        return (array) require $path;
    }
}
