<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Actions\BrowseMedia;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Actions\MoveMedia;
use Kryption\MediaHub\Actions\RenameFolder;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Backends\LegacyConversionMirror;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Exceptions\StorageMisconfigured;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Tests\Fixtures\LegacySchema;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\BrowseQuery;

/**
 * `table` MODE — THE PACKAGE'S ARBITER.
 *
 * ⚠️ IF PLUGGING THE LIBRARY ONTO EXISTING TABLES REQUIRES TOUCHING THE REST OF THE CODE, THE
 * ABSTRACTION IS WRONG — and that has to be known before a single view is written. This file
 * therefore runs against the REAL adopted schema, read from `information_schema` and reproduced
 * faithfully, absences included.
 *
 * ⚠️ AND THIS SCHEMA'S TRAPS DO NOT RAISE, THEY LIE. A root written as `0` and answered by a
 * `whereNull` returns an EMPTY library; a missing family filtered on a column that does not
 * exist returns an SQL error on the first click. Each one has its test.
 */
class TableBackendTest extends TestCase
{
    private static ?string $current = null;

    /**
     * ⚠️ THE SAME FILE RUNS ON TWO ENGINES, AND THAT IS THE WHOLE POINT. On SQLite against a
     * mirror of the schema, everywhere; and on MariaDB against the tables ACTUALLY migrated,
     * when `MEDIAHUB_MARIADB` is set. The mirror reproduces the types and the absences, not the
     * behaviour of unsigned integers, `tinyint`, or collations — a bench stopping there would
     * declare sound an adoption that breaks on the first write.
     */
    private function onMariaDb(): bool
    {
        return getenv('MEDIAHUB_MARIADB') !== false && getenv('MEDIAHUB_MARIADB') !== '';
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.backend.driver', 'table');
        $app['config']->set('mediahub.backend.preset', 'legacy');
        $app['config']->set('mediahub.routes.enabled', false);

        if (! $this->onMariaDb()) {
            return;
        }

        $app['config']->set('database.default', 'mediahub_mariadb');
        $app['config']->set('database.connections.mediahub_mariadb', [
            'driver' => 'mysql',
            'host' => getenv('MEDIAHUB_DB_HOST') ?: 'mariadb',
            'port' => getenv('MEDIAHUB_DB_PORT') ?: '3306',
            'database' => getenv('MEDIAHUB_DB_NAME') ?: '',
            'username' => getenv('MEDIAHUB_DB_USER') ?: 'root',
            'password' => getenv('MEDIAHUB_DB_PASS') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->onMariaDb()) {
            /*
             * ⚠️ WE DO NOT TOUCH THE HOST'S TABLES: they are ALREADY migrated, and that is
             * precisely what we want to exercise. Creating them ourselves would amount to
             * testing our own copy a second time, with more ceremony.
             */
            $this->cleanMariaDb();
            $this->seedOrganisations();
        } else {
            LegacySchema::create();
        }

        /*
         * ⚠️ IT IS THE REAL MIGRATION THAT RUNS, NOT A COPY IN THE FIXTURE. Copying its
         * definition here would give two truths for one table: the bench would validate the
         * copy, and the migration actually shipped could diverge without anything falling over.
         */
        (require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php')->up();

        self::$current = '7';

        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return TableBackendTest::key();
            }

            /**
             * ⚠️ THIS IS WHAT THE HOST WRITES, AND IT GOES THROUGH THE MAP. Without it, it would
             * write `scope_key`, a column that does not exist here — and the scoping, the only
             * thing that really protects, would fall over on the first listing.
             */
            public function constrain(Builder $query): Builder
            {
                return $query->where($query->getModel()::column('scope_key'), TableBackendTest::key());
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->onMariaDb()) {
            /*
             * ⚠️ WE ERASE OUR ROWS, NOT THE TABLES. The database is shared: dropping
             * `media_files` there would force the next user to replay every migration, and they
             * would not understand why.
             */
            $this->cleanMariaDb();
        } else {
            Schema::dropIfExists('mediahub_conversions');
            LegacySchema::drop();
        }

        parent::tearDown();
    }

    /** ⚠️ IN DEPENDENCY ORDER: what references goes before what is referenced. */
    private function cleanMariaDb(): void
    {
        Schema::dropIfExists('mediahub_conversions');

        DB::table('media_files')->delete();
        DB::table('media_folders')->delete();
        DB::table('organizations')->whereIn('id', [7, 9])->delete();
        DB::table('users')->whereIn('id', [3, 42])->delete();
    }

    /**
     * ⚠️ `organization_id` CARRIES A REAL FOREIGN KEY, unlike `folder_id`. That is one of the
     * facts the SQLite mirror does not reproduce: without an existing organisation the engine
     * refuses the write — and that is exactly what we want to exercise.
     */
    private function seedOrganisations(): void
    {
        foreach ([7, 9] as $id) {
            DB::table('organizations')->insert([
                'id' => $id,
                'name' => 'Bench '.$id,
                'slug' => 'bench-'.$id,
                'can_administrate' => 0,
                'demo_mode' => 0,
            ]);
        }

        foreach ([3, 42] as $id) {
            DB::table('users')->insert([
                'id' => $id,
                'gender' => 'none',
                'name' => 'bench'.$id,
                'email' => 'bench'.$id.'@example.test',
                'password' => 'x',
            ]);
        }
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function media(array $attributes = []): Media
    {
        return Media::create(array_merge([
            'owner_id' => 3,
            'path' => 'orgs/7/library/report.pdf',
            'name' => 'Report',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'disk' => 'objects',
        ], $attributes));
    }

    /** The row as the database keeps it, with no translation at all. */
    private function raw(int $id): object
    {
        return DB::table('media_files')->where('id', $id)->first();
    }

    // ── Does the preset describe the real schema? ────────────────────────────

    public function test_the_preset_only_names_columns_that_exist(): void
    {
        /*
         * ⚠️ A TYPO IN THE MAP WOULD ONLY SHOW ON THE FIRST QUERY, against a schema no bench has
         * to hand. This witness catches it here.
         */
        foreach ([[Media::class, LegacySchema::FILE_COLUMNS], [MediaFolder::class, LegacySchema::FOLDER_COLUMNS]] as [$model, $real]) {
            foreach ($model::columnMap()->physicalColumns() as $logical => $physical) {
                $this->assertContains(
                    $physical,
                    $real,
                    "the map points \"{$logical}\" at \"{$physical}\", which does not exist"
                );
            }
        }
    }

    public function test_the_targeted_tables_are_the_hosts(): void
    {
        $this->assertSame('media_files', (new Media())->getTable());
        $this->assertSame('media_folders', (new MediaFolder())->getTable());
    }

    public function test_the_conversions_table_is_added_alongside(): void
    {
        /*
         * ⚠️ IT IS ADDED, IT REPLACES NOTHING. The target schema has none — its thumbnails live
         * in the `options` blob. This table brings what the blob cannot carry: a derivative's
         * STATE and ERROR, therefore the ability to regenerate a single one.
         */
        $this->assertTrue(HostSchema::hasTable('conversions'));
        $this->assertSame('mediahub_conversions', HostSchema::table('conversions'));
    }

    public function test_the_adopted_migration_does_nothing_when_the_table_is_there(): void
    {
        /*
         * ⚠️ THIS IS THE ADOPTION PROMISE: the package's migrations have no effect when the tables
         * exist. A host replays `migrate` — after a deployment, after a restore — and the second
         * run must be a non-event, not an error blocking every migration behind it.
         */
        $migration = require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php';

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('mediahub_conversions'));
    }

    public function test_the_linking_table_does_not_exist_and_says_so(): void
    {
        /*
         * ⚠️ AND THAT ONE IS NOT INVENTED. The target schema scatters half a dozen `*_media_id`
         * columns through the application; creating the table here would leave it empty for
         * weeks, while suggesting it is in use.
         */
        $this->assertFalse(HostSchema::hasTable('mediables'));

        $this->expectException(StorageMisconfigured::class);

        HostSchema::table('mediables');
    }

    // ── Translating a NAME ───────────────────────────────────────────────────

    public function test_the_path_is_written_into_the_url_column(): void
    {
        $media = $this->media(['path' => 'orgs/7/library/photo.jpg']);

        $this->assertSame('orgs/7/library/photo.jpg', $this->raw($media->id)->url);
        $this->assertSame('orgs/7/library/photo.jpg', $media->fresh()->path);
    }

    public function test_the_scope_is_written_into_organization_id(): void
    {
        $media = $this->media();

        $this->assertSame(7, (int) $this->raw($media->id)->organization_id);
        $this->assertSame('7', (string) $media->fresh()->scope_key);
    }

    public function test_the_owner_is_written_into_user_id(): void
    {
        /*
         * ⚠️ `user_id` IS `NOT NULL` WITHOUT A DEFAULT: it is the only column the package does
         * not know about and for which the database demands a value. Without this mapping,
         * every upload fails.
         */
        $media = $this->media(['owner_id' => 42]);

        $this->assertSame(42, (int) $this->raw($media->id)->user_id);
    }

    public function test_the_route_key_is_the_identifier(): void
    {
        $media = $this->media();

        $this->assertSame('id', $media->getRouteKeyName());
        $this->assertSame($media->id, $media->getRouteKey());
        $this->assertSame($media->id, $media->uuid);
    }

    // ── Translating a VALUE ──────────────────────────────────────────────────

    public function test_the_root_is_written_as_zero_and_read_as_null(): void
    {
        /*
         * ⚠️ THIS SCHEMA'S MAIN TRAP. `folder_id` is `NOT NULL DEFAULT 0`: writing `null` would
         * fail, and reading `0` as a folder identifier would send us looking for a folder number
         * zero that does not exist.
         */
        $media = $this->media();

        $this->assertSame(0, (int) $this->raw($media->id)->folder_id);
        $this->assertNull($media->fresh()->folder_id);
    }

    public function test_listing_the_root_does_find_the_files(): void
    {
        /*
         * ⚠️ THE TEST THAT MATTERS. A `whereNull('folder_id')` on this schema returns ZERO rows
         * — an empty library, without the slightest error, on a full database.
         */
        $this->media();
        $this->media(['path' => 'orgs/7/library/second.pdf']);

        $page = $this->app->make(BrowseMedia::class)(
            BrowseQuery::fromInput([], null, rootOnly: true)
        );

        $this->assertSame(2, $page->total());
    }

    public function test_moving_a_media_back_to_the_root_writes_zero_not_null(): void
    {
        /*
         * ⚠️ THE OTHER DIRECTION, AND IT IS DISTINCT. Reading a `0` as "no folder" says nothing
         * about writing: `folder_id` is `NOT NULL`, so writing the package's `null` into it
         * would make the query fail — a move to the root, refused by the database, on an
         * operation that is in no way exotic.
         */
        $folder = $this->app->make(CreateFolder::class)('Clients', null, ['owner_id' => 3]);
        $media = $this->media(['folder_id' => $folder->getKey()]);

        $this->app->make(MoveMedia::class)($media, null);

        $this->assertSame(0, (int) $this->raw($media->id)->folder_id);
        $this->assertNull($media->fresh()->folder_id);
    }

    public function test_visibility_is_an_integer_in_the_database(): void
    {
        $media = $this->media(['visibility' => 'public']);

        $this->assertSame(1, (int) $this->raw($media->id)->is_public);
        $this->assertSame('public', $media->fresh()->visibility);
    }

    public function test_free_form_properties_live_in_options(): void
    {
        $media = $this->media(['custom_properties' => ['alt' => 'A report']]);

        $this->assertStringContainsString('alt', (string) $this->raw($media->id)->options);
        $this->assertSame(['alt' => 'A report'], $media->fresh()->custom_properties);
    }

    // ── Filling an ABSENCE ───────────────────────────────────────────────────

    public function test_the_file_name_and_extension_are_derived_from_the_path(): void
    {
        $media = $this->media(['path' => 'orgs/7/library/my-report.PDF']);

        $this->assertSame('my-report.PDF', $media->file_name);
        $this->assertSame('pdf', $media->extension);
    }

    public function test_the_family_is_derived_from_the_mime_type(): void
    {
        $this->assertSame('image', $this->media(['mime_type' => 'image/png'])->type);
        $this->assertSame('video', $this->media(['mime_type' => 'video/mp4'])->type);
        $this->assertSame('document', $this->media(['mime_type' => 'application/pdf'])->type);
    }

    public function test_writing_an_absent_column_does_not_bring_the_upload_down(): void
    {
        /*
         * ⚠️ THE PACKAGE SETS `checksum`, `width` AND `height` ON EVERY UPLOAD. Raising would
         * make a perfectly valid upload fail because the host's schema is poorer. What cannot be
         * kept is not kept, and that is all.
         */
        $media = $this->media(['checksum' => str_repeat('a', 64), 'width' => 800, 'height' => 600]);

        $this->assertNotNull($media->id);
        $this->assertNull($media->fresh()->checksum);
        $this->assertNull($media->fresh()->width);
    }

    public function test_the_family_filter_works_without_a_family_column(): void
    {
        /*
         * ⚠️ WITHOUT THE RECONSTRUCTION, THIS FILTER WOULD QUERY A `type` COLUMN THAT DOES NOT
         * EXIST — therefore an SQL error on the first click on "Images".
         */
        $this->media(['mime_type' => 'image/png', 'path' => 'orgs/7/library/a.png']);
        $this->media(['mime_type' => 'video/mp4', 'path' => 'orgs/7/library/b.mp4']);

        $page = $this->app->make(BrowseMedia::class)(
            BrowseQuery::fromInput(['types' => ['image']], null, rootOnly: true)
        );

        $this->assertSame(1, $page->total());
        $this->assertSame('image/png', $page->items()[0]->mime_type);
    }

    public function test_the_document_filter_reconstructs_the_list_of_types(): void
    {
        $this->media(['mime_type' => 'application/pdf', 'path' => 'orgs/7/library/a.pdf']);
        $this->media(['mime_type' => 'image/png', 'path' => 'orgs/7/library/b.png']);

        $page = $this->app->make(BrowseMedia::class)(
            BrowseQuery::fromInput(['types' => ['document']], null, rootOnly: true)
        );

        $this->assertSame(1, $page->total());
    }

    public function test_uploading_does_not_look_for_a_duplicate_without_a_checksum(): void
    {
        /*
         * ⚠️ QUERYING `checksum` HERE WOULD PRODUCE AN SQL ERROR ON EVERY UPLOAD. Without the
         * column there is no duplicate to recognise — which is the host's behaviour today.
         */
        $this->assertFalse(Media::hasColumn('checksum'));
    }

    public function test_asking_for_an_absent_column_raises_rather_than_guessing(): void
    {
        /*
         * ⚠️ THE NATURAL FALLBACK WOULD BE TO RETURN THE LOGICAL NAME, and it would be worse
         * than the error: the query would go out with `WHERE checksum = …` against a table
         * without that column, and the engine's message would never say "your schema is poorer
         * than expected". Writing `Media::column('checksum')` in adopted mode is a development
         * mistake, and it has to show at development time.
         */
        $this->expectException(StorageMisconfigured::class);

        Media::column('checksum');
    }

    // ── The tree without a materialised path ─────────────────────────────────

    public function test_a_folder_reconstructs_its_path_by_climbing(): void
    {
        $root = $this->app->make(CreateFolder::class)('Clients', null, ['owner_id' => 3]);
        $child = $this->app->make(CreateFolder::class)('Durand', $root, ['owner_id' => 3]);

        $this->assertSame('clients', $root->path);
        $this->assertSame('clients/durand', $child->fresh()->path);
        $this->assertSame(1, $child->fresh()->depth);
    }

    public function test_the_root_parent_is_written_as_zero(): void
    {
        $root = $this->app->make(CreateFolder::class)('Clients', null, ['owner_id' => 3]);

        $raw = DB::table('media_folders')->where('id', $root->id)->first();

        $this->assertSame(0, (int) $raw->parent_id);
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_renaming_a_folder_rewrites_no_descendant(): void
    {
        /*
         * ⚠️ THE PLEASANT SIDE OF THE ABSENCE. Without a materialised path there is nothing to
         * keep up to date: the child's path FOLLOWS, because it is computed and not stored.
         */
        $root = $this->app->make(CreateFolder::class)('Clients', null, ['owner_id' => 3]);
        $child = $this->app->make(CreateFolder::class)('Durand', $root, ['owner_id' => 3]);

        $this->app->make(RenameFolder::class)($root, 'Accounts');

        $this->assertSame('accounts/durand', $child->fresh()->path);
    }

    public function test_the_content_of_a_folder_can_be_listed(): void
    {
        $folder = $this->app->make(CreateFolder::class)('Clients', null, ['owner_id' => 3]);
        $this->media(['folder_id' => $folder->getKey()]);
        $this->media(['path' => 'orgs/7/library/root.pdf']);

        $page = $this->app->make(BrowseMedia::class)(
            BrowseQuery::fromInput([], $folder)
        );

        $this->assertSame(1, $page->total());
    }

    // ── The conversions mirror, for cohabitation ─────────────────────────────

    public function test_the_reflection_is_written_into_the_block_the_old_screen_reads(): void
    {
        /*
         * ⚠️ THE OLD MODULE CAN ONLY READ A THUMBNAIL IN ONE PLACE:
         * `media_files.options['thumb']`, a relative path on the media's disk — verified in its
         * `getThumbnailUrlAttribute()` accessor. A derivative recorded only in the package's
         * table would be invisible everywhere the user looks today.
         */
        $media = $this->media();

        $this->app->make(LegacyConversionMirror::class)
            ->reflect($media, 'thumb', 'orgs/7/library/report-thumb.png');

        $options = json_decode((string) $this->raw($media->id)->options, true);

        $this->assertSame('orgs/7/library/report-thumb.png', $options['thumb']);
    }

    public function test_the_reflection_disappears_when_the_derivative_can_no_longer_be_served(): void
    {
        $media = $this->media(['custom_properties' => ['thumb' => 'orgs/7/library/old.png']]);

        $this->app->make(LegacyConversionMirror::class)->forget($media, 'thumb');

        $options = json_decode((string) $this->raw($media->id)->options, true);

        $this->assertArrayNotHasKey('thumb', (array) $options);
    }

    public function test_only_declared_derivatives_are_mirrored(): void
    {
        /*
         * ⚠️ THE MIRROR IS A TRANSITIONAL MEASURE, NOT A BEHAVIOUR. Reflecting everything that
         * goes past would fill the block with keys the old module does not read — and would
         * therefore not erase on deletion, since it only erases the sizes declared in its own
         * configuration. Orphaned thumbnails, through excess of zeal.
         */
        $mirror = $this->app->make(LegacyConversionMirror::class);

        $this->assertTrue($mirror->reflects('thumb'));
        $this->assertFalse($mirror->reflects('preview'));

        $media = $this->media();
        $mirror->reflect($media, 'preview', 'orgs/7/library/preview.png');

        $this->assertNull($this->raw($media->id)->options);
    }

    // ── Scoping, on the host's column ────────────────────────────────────────

    public function test_scoping_goes_through_organization_id(): void
    {
        $mine = $this->media();

        self::$current = '9';
        $foreign = $this->media(['path' => 'orgs/9/library/other.pdf']);
        self::$current = '7';

        $this->assertNotNull(Media::query()->find($mine->id));
        $this->assertNull(Media::query()->find($foreign->id));
    }
}
