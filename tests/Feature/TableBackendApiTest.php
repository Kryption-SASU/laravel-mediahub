<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Exceptions\StorageMisconfigured;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Contracts\MediaOwner;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\OwnerContext;
use Kryption\MediaHub\Tests\Fixtures\LegacySchema;
use Kryption\MediaHub\Tests\Fixtures\LegacyUser;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;

/**
 * `table` MODE, OVER HTTP — THE BENCH THAT WAS MISSING.
 *
 * ⚠️ `TableBackendTest` TURNS THE ROUTES OFF. It exercises the actions and the column map
 * directly, which is what it is for — but it means the HTTP layer, resources and form requests
 * alike, had never once run against this backend. And this backend is the one whose route key is
 * the host's integer `id`: `standalone` keys on a `uuid`, so every other file in this suite sends
 * and receives strings, and every one of them was green.
 *
 * ⚠️ WHAT THAT COST, MEASURED ON A REAL HOST ON 25/08/2026: trashing, restoring, deleting for
 * good, downloading a selection and creating a folder inside another all answered 422 — "media.0
 * must be a string" — because the API was refusing the very keys it had just handed out. Five
 * operations, one cause, and a suite that could not see any of it.
 *
 * ⚠️ SO THE TESTS BELOW GO ROUND THE LOOP RATHER THAN ASSERTING A SHAPE. They read a key from
 * the API's own answer and give it straight back, which is exactly what a client does and
 * exactly what nothing here was doing.
 */
class TableBackendApiTest extends TestCase
{
    private static ?string $current = null;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.backend.driver', 'table');
        $app['config']->set('mediahub.backend.preset', 'legacy');

        /*
         * ⚠️ THE ROUTES ARE ON, AND THAT IS THE ENTIRE POINT OF THIS FILE. Left off — as they
         * are in `TableBackendTest` — nothing here can fail, and nothing there could either.
         */
        $app['config']->set('mediahub.routes.enabled', true);
        $app['config']->set('mediahub.routes.middleware', ['web']);

        $app['config']->set('filesystems.disks.objects', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-table-api',
            'serve' => false,
            'throw' => false,
        ]);

        $app['config']->set('mediahub.storage.disk', 'objects');
    }

    protected function setUp(): void
    {
        parent::setUp();

        LegacySchema::create();

        (require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php')->up();

        self::$current = '7';

        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return TableBackendApiTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where($query->getModel()::column('scope_key'), TableBackendApiTest::key());
            }
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('mediahub_conversions');
        LegacySchema::drop();

        $this->app['files']->deleteDirectory(sys_get_temp_dir().'/mediahub-table-api');

        parent::tearDown();
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function media(array $attributes = []): Media
    {
        $media = Media::create(array_merge([
            'owner_id' => 3,
            'path' => 'orgs/7/library/'.uniqid('f', true).'.pdf',
            'name' => 'Report',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'disk' => 'objects',
        ], $attributes));

        $this->app['filesystem']->disk('objects')->put($media->path, 'bytes');

        return $media;
    }

    /**
     * ⚠️ THE OWNER IS PASSED, BECAUSE THE ADOPTED SCHEMA INSISTS. `media_folders.user_id` is
     * `NOT NULL` there — one of the absences the preset has to live with, and one the package's
     * own tables do not have.
     */
    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return $this->app->make(CreateFolder::class)($name, $parent, ['owner_id' => 3]);
    }

    // ── What the API says a key is ───────────────────────────────────────────

    /**
     * ⚠️ THE PUBLISHED CONTRACT SAYS `id: string`, FOR EVERY DRIVER. Here the route key is an
     * auto-incrementing integer, and left uncast the same field came back as a number — so the
     * contract was true on one installation and false on another, with nothing to tell them
     * apart but the configuration file.
     */
    public function test_a_media_key_is_a_string_even_when_the_column_is_an_integer(): void
    {
        $this->media();

        $body = $this->getJson('/media')->assertOk()->json('data');

        $this->assertIsInt(Media::query()->first()?->getRouteKey());
        $this->assertIsString($body['media'][0]['id']);
    }

    public function test_a_folder_key_is_a_string_too(): void
    {
        $this->folder('Clients');

        $body = $this->getJson('/media')->assertOk()->json('data');

        $this->assertIsString($body['folders'][0]['id']);
        $this->assertNull($body['folders'][0]['parent_id']);
    }

    /** ⚠️ AND THE ABSENCE OF A PARENT STAYS `null` — cast, it would become `""`, a key the
     * client would hand back on the next write. */
    public function test_a_media_at_the_root_reports_no_folder_rather_than_an_empty_one(): void
    {
        $this->media();

        $body = $this->getJson('/media')->assertOk()->json('data');

        $this->assertNull($body['media'][0]['folder_id']);
    }

    public function test_a_media_in_a_folder_reports_that_folder_as_a_string(): void
    {
        $folder = $this->folder('Clients');
        $this->media(['folder_id' => $folder->getKey()]);

        $body = $this->getJson('/media?folder='.$folder->getRouteKey())->assertOk()->json('data');

        $this->assertIsString($body['media'][0]['folder_id']);
    }

    // ── Giving back what it was given ────────────────────────────────────────

    public function test_a_selection_of_the_keys_it_handed_out_is_accepted(): void
    {
        $this->media();

        $id = $this->getJson('/media')->assertOk()->json('data.media.0.id');

        $this->postJson('/media/trash', ['media' => [$id]])->assertOk();

        $this->assertNotNull(Media::onlyTrashed()->first());
    }

    /**
     * ⚠️ AND AN INTEGER IS ACCEPTED AS WELL. JSON cannot tell "the identifier 12" from "the
     * number 12": a client written in a language where the key is an integer sends a number, and
     * refusing it produces a 422 that names a type rather than a problem.
     */
    public function test_an_integer_key_is_read_as_the_key_it_is(): void
    {
        $media = $this->media();

        $this->postJson('/media/trash', ['media' => [(int) $media->getRouteKey()]])->assertOk();

        $this->assertNotNull(Media::onlyTrashed()->first());
    }

    public function test_deleting_for_good_takes_an_integer_key(): void
    {
        $media = $this->media();
        $path = $media->path;

        $this->postJson('/media/trash/purge', ['media' => [(int) $media->getRouteKey()]])->assertOk();

        $this->assertNull(Media::withTrashed()->first());
        $this->assertFalse($this->app['filesystem']->disk('objects')->exists($path));
    }

    public function test_restoring_takes_an_integer_key(): void
    {
        $media = $this->media();
        $media->delete();

        $this->postJson('/media/trash/restore', ['media' => [(int) $media->getRouteKey()]])->assertOk();

        $this->assertNull(Media::onlyTrashed()->first());
    }

    public function test_a_folder_is_created_inside_the_one_named_by_an_integer_key(): void
    {
        /* ⚠️ SIGNED IN, BECAUSE THIS SCHEMA INSISTS ON AN OWNER. Nobody acting means nothing
         * written — which on a `NOT NULL` column is a refused insert, and that is its own test
         * further down rather than an accident here. */
        $this->actingAs(new LegacyUser(['id' => 42]));

        $parent = $this->folder('Clients');

        $body = $this->postJson('/media/folders', [
            'name' => 'Contracts',
            'parent' => (int) $parent->getRouteKey(),
        ])->assertStatus(201)->json('data');

        $this->assertSame((string) $parent->getRouteKey(), $body['parent_id']);
    }

    public function test_a_media_is_moved_into_the_folder_named_by_an_integer_key(): void
    {
        $media = $this->media();
        $folder = $this->folder('Clients');

        $body = $this->patchJson('/media/'.$media->getRouteKey(), [
            'folder' => (int) $folder->getRouteKey(),
        ])->assertOk()->json('data');

        $this->assertSame((string) $folder->getRouteKey(), $body['folder_id']);
    }

    /**
     * ⚠️ WHAT IS STILL REFUSED HAS TO STAY REFUSED. The keys are put into their declared form
     * before validation rather than allowed through it, so a shape that is genuinely wrong —
     * an array where a key belongs — still fails, and still says which.
     */
    public function test_something_that_is_not_a_key_is_still_refused(): void
    {
        $this->postJson('/media/trash', ['media' => [['id' => 1]]])->assertStatus(422);
    }

    public function test_an_empty_selection_is_still_refused(): void
    {
        $this->postJson('/media/trash', ['media' => []])->assertStatus(422);
    }

    // ── The table both modes want to call the same thing ─────────────────────

    /**
     * ⚠️ `mediahub_conversions` IS NAMED THE SAME IN BOTH MODES, AND ONLY ONE SHAPE WORKS HERE.
     * The standalone migration gives it a `bigint` key and a foreign key onto the package's own
     * `mediahub_files`; here the media live in the HOST's table, so that key points somewhere
     * they are not.
     *
     * ⚠️ AND THE ORDINARY INSTALLATION ORDER PRODUCES EXACTLY THAT. The package ships with
     * `standalone`, so `composer require` then `migrate` — before anyone sets the driver —
     * creates the whole standalone schema. The adopted migration then saw "the table exists"
     * and returned, and the library refused every image upload from that moment on, with a
     * constraint violation and nothing to connect it to a migration run days earlier. Measured
     * on a real host on 25/08/2026.
     */
    public function test_it_takes_over_an_empty_conversions_table_the_standalone_migration_made(): void
    {
        $this->standaloneConversions();

        try {
            (require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php')->up();

            /* ⚠️ THE TABLE IS OURS NOW: the host's key type, and nothing pointing at
             * `mediahub_files` — which in this mode holds no media and never will. */
            $this->assertSame([], Schema::getForeignKeys('mediahub_conversions'));
        } finally {
            Schema::dropIfExists('mediahub_conversions');
            Schema::dropIfExists('mediahub_files');
        }
    }

    /**
     * ⚠️ BUT A POPULATED ONE IS A LIBRARY, AND DROPPING IT IS NOT A MIGRATION'S DECISION. Those
     * rows are the standalone library's derivatives; whoever switched drivers is the only one
     * who knows whether they are still worth anything.
     */
    public function test_it_refuses_a_conversions_table_that_has_been_used(): void
    {
        $this->standaloneConversions();

        DB::table('mediahub_files')->insert(['id' => 1]);
        DB::table('mediahub_conversions')->insert(['media_id' => 1, 'name' => 'thumb']);

        try {
            $this->expectException(StorageMisconfigured::class);
            $this->expectExceptionMessageMatches('/holds 1 rows/');

            (require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php')->up();
        } finally {
            Schema::dropIfExists('mediahub_conversions');
            Schema::dropIfExists('mediahub_files');
        }
    }

    /** The shape the standalone migration leaves behind: a bigint key, and a key onto its own table. */
    private function standaloneConversions(): void
    {
        Schema::dropIfExists('mediahub_conversions');

        Schema::create('mediahub_files', static function (Blueprint $blueprint): void {
            $blueprint->id();
        });

        Schema::create('mediahub_conversions', static function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('media_id')->constrained('mediahub_files')->cascadeOnDelete();
            $blueprint->string('name');
            $blueprint->unique(['media_id', 'name']);
        });
    }

    /** ⚠️ AND IT STAYS IDEMPOTENT ON ITS OWN TABLE — a second `migrate` must not raise. */
    public function test_it_accepts_the_table_it_made_itself(): void
    {
        (require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php')->up();

        $this->assertTrue(Schema::hasTable('mediahub_conversions'));
        $this->assertSame([], Schema::getForeignKeys('mediahub_conversions'));
    }

    /**
     * ⚠️ AND IT LEAVES ITS OWN ROWS WHERE THEY ARE. "Take the table over when nothing is in it"
     * must not be read as "empty it": a second `migrate` on a working installation would then
     * throw away every derivative record, and the only sign would be thumbnails regenerating.
     * Caught by mutation on 25/08/2026 — the earlier test could not tell the two apart, because
     * the table it looked at was empty either way.
     */
    public function test_it_leaves_the_rows_in_its_own_table_alone(): void
    {
        $media = $this->media();

        DB::table('mediahub_conversions')->insert([
            'media_id' => $media->getKey(),
            'name' => 'thumb',
            'disk' => 'objects',
            'path' => 'thumb.png',
        ]);

        (require __DIR__.'/../../database/migrations-adopted/0001_01_01_000100_create_mediahub_conversions_table.php')->up();

        $this->assertSame(1, DB::table('mediahub_conversions')->count());
    }

    // ── What a selection carries, before anything is done to it ──────────────

    /**
     * ⚠️ A FOLDER IS NEVER JUST A FOLDER. Trashing and purging take the whole subtree — that is
     * deliberate, and a folder deleted while its files stay visible would leave rows attached to
     * an absent parent. But it means "delete 1 folder" can mean "delete 1 folder and four hundred
     * files", and nothing could say so before the fact.
     */
    public function test_it_reports_what_a_folder_carries_all_the_way_down(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $root = $this->folder('Clients');
        $child = $this->folder('Acme', $root);
        $grandchild = $this->folder('Contracts', $child);

        $this->media(['folder_id' => $child->getKey()]);
        $this->media(['folder_id' => $grandchild->getKey()]);

        $body = $this->postJson('/media/contents', ['folders' => [$root->getRouteKey()]])
            ->assertOk()
            ->json('data');

        /* ⚠️ THE WHOLE BRANCH: the folder itself, its child, its grandchild. */
        $this->assertSame(3, $body['folders']);
        $this->assertSame(2, $body['media']);
    }

    /** ⚠️ AND AN EMPTY BRANCH SAYS SO, rather than leaving the caller to guess from a zero. */
    public function test_it_reports_a_folder_that_carries_nothing(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $folder = $this->folder('Clients');

        $body = $this->postJson('/media/contents', ['folders' => [$folder->getRouteKey()]])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $body['folders']);
        $this->assertSame(0, $body['media']);
    }

    /**
     * ⚠️ A FILE TICKED DIRECTLY THAT ALSO SITS INSIDE A TICKED FOLDER IS ONE FILE. Added rather
     * than unioned, the confirmation would name more than the action goes on to touch — and the
     * one number somebody reads before destroying something would be wrong upwards.
     */
    public function test_it_counts_a_file_once_even_when_it_is_reached_twice(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $folder = $this->folder('Clients');
        $media = $this->media(['folder_id' => $folder->getKey()]);

        $body = $this->postJson('/media/contents', [
            'folders' => [$folder->getRouteKey()],
            'media' => [$media->getRouteKey()],
        ])->assertOk()->json('data');

        $this->assertSame(1, $body['media']);
    }

    /** ⚠️ WHAT IS ALREADY IN THE TRASH COUNTS, because purging and restoring both act on it. */
    public function test_it_counts_what_is_already_in_the_trash(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $folder = $this->folder('Clients');
        $this->media(['folder_id' => $folder->getKey()])->delete();

        $body = $this->postJson('/media/contents', ['folders' => [$folder->getRouteKey()]])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $body['media']);
    }

    // ── What the upload measured, on a schema with nowhere to put it ─────────

    /**
     * ⚠️ THE UPLOAD ALREADY MEASURED THE PICTURE, AND THE VALUE WENT NOWHERE. `getimagesize()` is
     * called on the file as it lands, and the result was assigned to `width` and `height` — two
     * logical columns this preset maps to `null`, because the adopted tables really do not carry
     * them. Computed, assigned, dropped: the screen then showed nothing where a size belongs, on
     * every installation of this kind.
     *
     * ⚠️ AND IT IS THE ORIGINAL THAT IS MEASURED, from the uploaded file before a single
     * derivative exists. A thumbnail's dimensions are a fact about the thumbnail.
     */
    public function test_it_keeps_the_size_it_measured_where_the_schema_has_room(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, SampleImages::bytes('image/png'));

        $measured = getimagesize($path);

        $body = $this->post('/media', [
            'files' => [new UploadedFile($path, 'photo.png', 'image/png', null, true)],
        ])->assertSuccessful()->json('data');

        $this->assertFalse(Media::hasColumn('width'));
        $this->assertSame($measured[0], $body[0]['width']);
        $this->assertSame($measured[1], $body[0]['height']);
    }

    /** ⚠️ AND IT COMES BACK ON THE NEXT READ, not only in the answer to the upload. */
    public function test_the_size_survives_the_round_trip(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, SampleImages::bytes('image/png'));

        $this->post('/media', [
            'files' => [new UploadedFile($path, 'photo.png', 'image/png', null, true)],
        ])->assertSuccessful();

        $body = $this->getJson('/media')->assertOk()->json('data');

        $this->assertIsInt($body['media'][0]['width']);
        $this->assertIsInt($body['media'][0]['height']);
    }

    /**
     * ⚠️ A FILE THAT WAS THERE BEFORE STAYS WITHOUT ONE, and the screen leaves the row out rather
     * than printing an empty size. Nothing is measured retroactively: the bytes would have to be
     * read again, one object at a time, for a fact nobody asked for.
     */
    public function test_a_file_that_was_never_measured_reports_no_size(): void
    {
        $this->media(['mime_type' => 'image/png']);

        $body = $this->getJson('/media')->assertOk()->json('data');

        $this->assertNull($body['media'][0]['width']);
        $this->assertNull($body['media'][0]['height']);
    }

    /**
     * ⚠️ A SCHEMA WITH NEITHER PLACE KEEPS NOTHING, AND THE UPLOAD STILL WORKS. Not every adopted
     * schema has a free-form column: a host may map `custom_properties` to `null` the same way
     * this preset maps `width` to `null`, because the table simply has no such column. Writing
     * there is ignored in silence — deliberately, so a poorer schema cannot make a valid upload
     * fail — so what has to be checked is that the file still lands, and that the answer says no
     * size rather than inventing one.
     */
    public function test_a_schema_with_nowhere_at_all_keeps_no_size_and_still_accepts_the_file(): void
    {
        /* ⚠️ THE WHOLE FILE MAP, BECAUSE THAT IS WHAT NAMING IT MEANS. The deep merge goes one
         * level down, so `map.files` replaces the preset's rather than merging into it — which
         * is exactly how a host loses thirty-nine columns while correcting one. */
        $preset = require __DIR__.'/../../config/presets/legacy.php';

        config()->set('mediahub.backend.map.files', array_merge(
            $preset['map']['files'],
            ['custom_properties' => null],
        ));

        HostSchema::flush();

        $this->actingAs(new LegacyUser(['id' => 42]));

        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, SampleImages::bytes('image/png'));

        $body = $this->post('/media', [
            'files' => [new UploadedFile($path, 'photo.png', 'image/png', null, true)],
        ])->assertSuccessful()->json('data');

        $this->assertFalse(Media::hasColumn('custom_properties'));
        $this->assertCount(1, $body);
        $this->assertNull($body[0]['width']);
    }

    /**
     * ⚠️ THE PROPERTIES ARE FREE-FORM AND HOSTS WRITE IN THEM TOO. Anything in there that is not
     * a number must not reach a client that was promised one.
     */
    public function test_it_refuses_to_report_a_size_that_is_not_one(): void
    {
        $media = $this->media(['mime_type' => 'image/png']);
        $media->custom_properties = ['width' => 'wide', 'height' => null];
        $media->save();

        $body = $this->getJson('/media')->assertOk()->json('data');

        $this->assertNull($body['media'][0]['width']);
        $this->assertNull($body['media'][0]['height']);
    }

    // ── A page of a level is a page of both halves ───────────────────────────

    /**
     * ⚠️ A FOLDER IS ONE TILE, SO IT HAS TO BE ONE ITEM. Paginating the media alone put every
     * folder on top of every page: a level with twelve folders showed sixty tiles where it
     * promised forty-eight, the same twelve came back on page two, and "page 2 of 3" counted only
     * half of what was on screen.
     */
    public function test_a_page_holds_folders_and_files_together(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        foreach (range(1, 3) as $number) {
            $this->folder('folder '.$number);
        }

        foreach (range(1, 4) as $ignored) {
            $this->media();
        }

        $body = $this->getJson('/media?per_page=5')->assertOk()->json();

        $this->assertCount(3, $body['data']['folders']);
        $this->assertCount(2, $body['data']['media']);
        $this->assertSame(7, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['last_page']);
    }

    /** ⚠️ AND THE SECOND PAGE PICKS UP WHERE THE FIRST STOPPED — no row twice, none skipped. */
    public function test_the_next_page_carries_on_from_where_the_first_ended(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        foreach (range(1, 3) as $number) {
            $this->folder('folder '.$number);
        }

        foreach (range(1, 4) as $ignored) {
            $this->media();
        }

        $first = $this->getJson('/media?per_page=5')->assertOk()->json('data');
        $second = $this->getJson('/media?per_page=5&page=2')->assertOk()->json('data');

        $this->assertCount(0, $second['folders']);
        $this->assertCount(2, $second['media']);

        $seen = array_merge(
            array_column($first['media'], 'id'),
            array_column($second['media'], 'id'),
        );

        $this->assertCount(4, array_unique($seen));
    }

    /**
     * ⚠️ A PAGE PAST THE FOLDERS HOLDS ONLY FILES, and it starts at the right one. Off by the
     * folder count, the media would repeat what page one already showed.
     */
    public function test_a_page_beyond_the_folders_starts_at_the_right_file(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $this->folder('the only folder');

        foreach (range(1, 6) as $ignored) {
            $this->media();
        }

        $first = $this->getJson('/media?per_page=3')->assertOk()->json('data');
        $third = $this->getJson('/media?per_page=3&page=3')->assertOk()->json('data');

        $this->assertCount(1, $first['folders']);
        $this->assertCount(2, $first['media']);
        $this->assertCount(0, $third['folders']);

        $this->assertSame(
            [],
            array_intersect(array_column($first['media'], 'id'), array_column($third['media'], 'id')),
        );
    }

    /**
     * ⚠️ FOLDERS CAN OVERFLOW A PAGE ON THEIR OWN, and that is the case the rest of these tests
     * never reached: with fewer folders than fit, taking "all of them" and taking "as many as
     * there is room for" give the same answer, and so do skipping none and skipping the ones
     * already shown. Caught by mutation on 25/08/2026 — two bounds nothing could tell apart.
     */
    public function test_folders_spill_onto_the_following_pages(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        foreach (range(1, 5) as $number) {
            $this->folder('folder '.$number);
        }

        foreach (range(1, 2) as $ignored) {
            $this->media();
        }

        $first = $this->getJson('/media?per_page=3')->assertOk()->json();
        $second = $this->getJson('/media?per_page=3&page=2')->assertOk()->json('data');
        $third = $this->getJson('/media?per_page=3&page=3')->assertOk()->json('data');

        $this->assertCount(3, $first['data']['folders']);
        $this->assertCount(0, $first['data']['media']);

        /* ⚠️ THE SECOND PAGE STARTS AT THE FOURTH FOLDER, not at the first all over again. */
        $this->assertCount(2, $second['folders']);
        $this->assertCount(1, $second['media']);

        $this->assertCount(0, $third['folders']);
        $this->assertCount(1, $third['media']);

        $names = array_merge(
            array_column($first['data']['folders'], 'id'),
            array_column($second['folders'], 'id'),
        );

        $this->assertCount(5, array_unique($names));
        $this->assertSame(3, $first['meta']['last_page']);
    }

    /** ⚠️ AT LEAST ONE PAGE, EVEN EMPTY — "page 1 of 0" is a sentence nobody can act on. */
    public function test_an_empty_level_still_has_one_page(): void
    {
        $body = $this->getJson('/media')->assertOk()->json('meta');

        $this->assertSame(0, $body['total']);
        $this->assertSame(1, $body['last_page']);
    }

    /** ⚠️ FORTY-EIGHT UNLESS ASKED OTHERWISE, and the cap still refuses a page of a hundred
     * thousand — that was a one-parameter attack before it was a setting. */
    public function test_the_page_size_is_forty_eight_and_still_capped(): void
    {
        $this->assertSame(48, $this->getJson('/media')->assertOk()->json('meta.per_page'));

        $this->assertSame(
            100,
            $this->getJson('/media?per_page=100000')->assertOk()->json('meta.per_page'),
        );
    }

    // ── Putting a branch away, and taking it back ────────────────────────────

    /**
     * ⚠️ REPORTED FROM A REAL SCREEN ON 25/08/2026: a folder ticked in the trash was not restored,
     * nor anything inside it, while files at the root came back fine. This walks the whole round
     * trip over HTTP — trash the branch, look at the trash, restore the branch — because that is
     * the path the report describes and the one nothing was exercising.
     */
    public function test_a_whole_branch_goes_to_the_trash_and_comes_back(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $root = $this->folder('testfolder');
        $nested = $this->folder('imbrication', $root);

        $first = $this->media(['folder_id' => $nested->getKey()]);
        $second = $this->media(['folder_id' => $nested->getKey()]);

        $this->postJson('/media/trash', ['folders' => [$root->getRouteKey()]])->assertOk();

        $this->assertNotNull(MediaFolder::onlyTrashed()->find($nested->getKey()));
        $this->assertSame(2, Media::onlyTrashed()->count());

        /* ⚠️ THE TRASH LISTS THE BRANCH'S ROOT, which is what somebody ticks. */
        $listed = $this->getJson('/media?trashed=1')->assertOk()->json('data');

        /* ⚠️ AS A STRING, because that is what the contract says a key is — see above. */
        $this->assertSame([(string) $root->getRouteKey()], array_column($listed['folders'], 'id'));

        $this->postJson('/media/trash/restore', ['folders' => [$root->getRouteKey()]])->assertOk();

        $this->assertNull(MediaFolder::onlyTrashed()->first());
        $this->assertNull(Media::onlyTrashed()->first());
        $this->assertNotNull(Media::query()->find($first->getKey()));
        $this->assertNotNull(Media::query()->find($second->getKey()));
    }

    /**
     * ⚠️ AND THE BRANCH CAN BE WALKED INTO WHILE IT IS IN THERE. Resolved with the plain query,
     * a trashed folder answered "no such folder": the branch could be seen at its root and never
     * opened, so its nesting — and the files inside it — were unreachable. Somebody deciding what
     * to put back could not look at what they were putting back.
     */
    public function test_a_trashed_branch_can_be_walked_into(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $root = $this->folder('testfolder');
        $nested = $this->folder('imbrication', $root);
        $this->media(['folder_id' => $nested->getKey()]);

        $this->postJson('/media/trash', ['folders' => [$root->getRouteKey()]])->assertOk();

        $inside = $this->getJson('/media?trashed=1&folder='.$root->getRouteKey())
            ->assertOk()
            ->json('data');

        $this->assertSame([(string) $nested->getRouteKey()], array_column($inside['folders'], 'id'));

        $deeper = $this->getJson('/media?trashed=1&folder='.$nested->getRouteKey())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $deeper['media']);
    }

    /**
     * ⚠️ A THUMBNAIL SURVIVES THE TRASH, AND HAS TO BE SHOWABLE THERE. Trashing keeps every row
     * and every byte — that is what makes restoring possible — but a conversion asked for its
     * media got `null` the moment that media was thrown away, because the relation carried the
     * soft-delete scope. Building the derivative's URL then raised `conversion_without_media`,
     * so opening a folder in the trash answered with an error instead of a listing.
     *
     * ⚠️ AND SHOWING IT IS THE POINT: somebody deciding what to put back is looking at pictures.
     */
    public function test_a_trashed_picture_still_shows_its_thumbnail(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $folder = $this->folder('testfolder');
        $media = $this->media(['folder_id' => $folder->getKey(), 'mime_type' => 'image/png']);

        DB::table('mediahub_conversions')->insert([
            'media_id' => $media->getKey(),
            'name' => 'thumb',
            'disk' => 'objects',
            'path' => 'thumb.png',
            'state' => 'ready',
        ]);

        $this->postJson('/media/trash', ['folders' => [$folder->getRouteKey()]])->assertOk();

        $body = $this->getJson('/media?trashed=1&folder='.$folder->getRouteKey())
            ->assertOk()
            ->json('data');

        $this->assertNotNull($body['media'][0]['thumbnail_url']);
    }

    /**
     * ⚠️ THE TOP OF THE TRASH IS NOT THE ROOT OF THE LIBRARY. A folder thrown away on its own,
     * from inside a folder that is still there, has nowhere else to show: listed only "at the
     * root", it would be in the trash and invisible.
     */
    public function test_a_folder_thrown_away_from_inside_a_living_one_is_still_listed(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $living = $this->folder('Clients');
        $thrown = $this->folder('Acme', $living);

        $this->postJson('/media/trash', ['folders' => [$thrown->getRouteKey()]])->assertOk();

        $listed = $this->getJson('/media?trashed=1')->assertOk()->json('data');

        $this->assertSame([(string) $thrown->getRouteKey()], array_column($listed['folders'], 'id'));
    }

    /** ⚠️ AND THE LIBRARY GOES ON SHOWING ONLY WHAT IS ALIVE, which is the other half of the same
     * mistake: the trash used to list the living folders. */
    public function test_the_library_lists_none_of_what_was_thrown_away(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $kept = $this->folder('Clients');
        $thrown = $this->folder('Invoices');

        $this->postJson('/media/trash', ['folders' => [$thrown->getRouteKey()]])->assertOk();

        $listed = $this->getJson('/media')->assertOk()->json('data');

        $this->assertSame([(string) $kept->getRouteKey()], array_column($listed['folders'], 'id'));
    }

    // ── Who owns what the API creates ────────────────────────────────────────

    /**
     * ⚠️ `media_folders.user_id` IS `NOT NULL` IN THE ADOPTED SCHEMA, and nothing used to fill
     * it: the controller called `CreateFolder` with no owner at all, so creating a folder over
     * HTTP failed on a constraint violation — on every installation using this preset, at the
     * root as well as inside another folder.
     */
    public function test_a_folder_created_over_http_belongs_to_whoever_made_it(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $body = $this->postJson('/media/folders', ['name' => 'Contracts'])
            ->assertStatus(201)
            ->json('data');

        $folder = MediaFolder::query()->findOrFail($body['id']);

        $this->assertSame(42, (int) $folder->owner_id);
    }

    /** ⚠️ AND THE SAME FOR A FILE: `media_files.user_id` is `NOT NULL` too, so an upload with no
     * owner was not a file missing a fact — it was an upload that could not happen. */
    public function test_a_file_uploaded_over_http_belongs_to_whoever_sent_it(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, SampleImages::bytes('image/png'));

        $body = $this->post('/media', [
            'files' => [new UploadedFile($path, 'photo.png', 'image/png', null, true)],
        ])->assertSuccessful()->json('data');

        $this->assertCount(1, $body);
        $this->assertSame(42, (int) Media::query()->findOrFail($body[0]['id'])->owner_id);
    }

    /**
     * ⚠️ NOBODY SIGNED IN MEANS NOTHING WRITTEN, not a zero. A queue worker or a console command
     * acts with no user, and attributing its files to an invented identifier states a fact that
     * is false rather than leaving one unsaid.
     */
    public function test_nothing_is_attributed_when_nobody_is_acting(): void
    {
        $this->assertSame(
            [],
            OwnerContext::for(Media::class, $this->app->make(MediaOwner::class)),
        );
    }

    /**
     * ⚠️ AND THE TYPE IS ONLY WRITTEN WHERE THERE IS A COLUMN FOR IT. These tables carry a plain
     * `user_id` and no type at all; naming one would fail on SQL rather than on a rule.
     */
    public function test_no_owner_type_is_written_where_the_schema_has_none(): void
    {
        $this->actingAs(new LegacyUser(['id' => 42]));

        $this->assertFalse(Media::hasColumn('owner_type'));
        $this->assertArrayNotHasKey(
            'owner_type',
            OwnerContext::for(Media::class, $this->app->make(MediaOwner::class)),
        );
    }
}
