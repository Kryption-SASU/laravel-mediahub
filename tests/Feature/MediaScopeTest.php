<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Tests\TestCase;

/**
 * SCOPING — the property that outranks every other.
 *
 * ⚠️ THIS FILE EXISTS BECAUSE SCOPING WRITTEN INTO EVERY QUERY IS SCOPING THAT WILL BE
 * FORGOTTEN. The module this package replaces filtered its LISTINGS correctly — and not its
 * ACTIONS: a guessed identifier was enough to delete, rename or publish another customer's file.
 * The flaw came not from inattention but from where the rule was placed.
 *
 * Here it is a GLOBAL scope: no query can go around it without saying so.
 */
class MediaScopeTest extends TestCase
{
    use RefreshDatabase;

    /** The current scope, which the tests move at will. */
    private static ?string $current = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$current = 'clients/durand';

        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return MediaScopeTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', MediaScopeTest::key());
            }
        });
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function media(array $attributes = []): Media
    {
        return Media::create(array_merge([
            'disk' => 'media',
            'path' => 'invoices/report.pdf',
            'name' => 'report',
            'file_name' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 1024,
        ], $attributes));
    }

    // ── The tables ───────────────────────────────────────────────────────────

    public function test_the_standalone_tables_exist(): void
    {
        /*
         * ⚠️ FOUR TABLES, NOT FIVE. Sharing was dropped from v1: on the estate that served as
         * the field, it counted zero rows in eight years. A table no code fills cannot be
         * tested, and gets evolved blind.
         */
        foreach (['files', 'folders', 'conversions', 'mediables'] as $table) {
            $this->assertTrue(
                Schema::hasTable('mediahub_'.$table),
                sprintf('The "mediahub_%s" table is missing.', $table)
            );
        }
    }

    /**
     * ⚠️ THE PACKAGE ONLY CREATES ITS OWN TABLES, and this list is exhaustive.
     *
     * The first version of this test checked for the absence of a column in a `users` table…
     * which does not exist on this bench. It would have been green whatever happened — a test
     * that cannot fail says nothing. This one compares the full set of tables present: one extra
     * table brings it down.
     *
     * The stake is concrete: the module this package replaces added a quota column to its host's
     * users table. A package does not write into a table that is not its own.
     */
    public function test_the_package_only_creates_its_own_tables(): void
    {
        $tables = array_map(
            static fn (array $table): string => $table['name'],
            Schema::getTables()
        );

        $foreign = array_values(array_filter(
            $tables,
            static fn (string $name): bool => ! str_starts_with($name, 'mediahub_')
                && ! in_array($name, ['migrations', 'sqlite_sequence'], true)
        ));

        $this->assertSame([], $foreign, 'The package created tables that are not its own.');
    }

    // ── The key, set on creation ─────────────────────────────────────────────

    /**
     * ⚠️ WITHOUT THIS, A MEDIA RECORDED THROUGH A DOOR WITH NO CONTEXT STAYS ORPHANED —
     * therefore invisible to its own customer, absent from their export, and refused for
     * download forever. 503 media were counted in that state on a real estate.
     */
    public function test_the_scope_key_is_set_on_creation(): void
    {
        $media = $this->media();

        $this->assertSame('clients/durand', $media->fresh()->scope_key);
    }

    public function test_an_explicitly_given_key_is_not_overwritten(): void
    {
        $media = $this->media(['scope_key' => 'clients/martin']);

        $this->assertSame('clients/martin', $media->fresh()->getAttribute('scope_key'));
    }

    // ── Reading, bounded ─────────────────────────────────────────────────────

    /** ⚠️ THE TEST THAT COUNTS: what belongs to somebody else does not exist. */
    public function test_media_of_another_scope_cannot_be_seen(): void
    {
        $mine = $this->media(['name' => 'mine']);

        self::$current = 'clients/martin';
        $theirs = $this->media(['name' => 'theirs']);

        self::$current = 'clients/durand';

        $seen = Media::query()->pluck('id')->all();

        $this->assertContains($mine->id, $seen);
        $this->assertNotContains($theirs->id, $seen);
    }

    /**
     * ⚠️ AND IT CANNOT BE REACHED BY ITS IDENTIFIER EITHER. That is precisely the original
     * module's flaw: the listing was scoped, RETRIEVAL BY IDENTIFIER was not.
     */
    public function test_a_guessed_identifier_grants_no_access(): void
    {
        self::$current = 'clients/martin';
        $theirs = $this->media();

        self::$current = 'clients/durand';

        $this->assertNull(Media::query()->find($theirs->id));
    }

    /** And deletion cannot reach it any more than that. */
    public function test_a_deletion_does_not_cross_the_scope(): void
    {
        self::$current = 'clients/martin';
        $theirs = $this->media();

        self::$current = 'clients/durand';
        Media::query()->where('id', $theirs->id)->delete();

        self::$current = 'clients/martin';
        $this->assertNotNull(Media::query()->find($theirs->id));
    }

    public function test_folders_are_scoped_the_same_way(): void
    {
        $mine = MediaFolder::create(['name' => 'Invoices']);

        self::$current = 'clients/martin';
        $theirs = MediaFolder::create(['name' => 'Invoices']);

        self::$current = 'clients/durand';

        $seen = MediaFolder::query()->pluck('id')->all();

        $this->assertContains($mine->id, $seen);
        $this->assertNotContains($theirs->id, $seen);
    }

    // ── The way out, named ───────────────────────────────────────────────────

    /**
     * ⚠️ A NAMED WAY OUT IS BETTER THAN SCOPING WORKED AROUND by writing the query by hand.
     * Maintenance needs to see everything; it has to say so.
     */
    public function test_maintenance_can_see_everything_by_saying_so(): void
    {
        $mine = $this->media();

        self::$current = 'clients/martin';
        $theirs = $this->media();

        $seen = Media::query()->withoutMediaScope()->pluck('id')->all();

        $this->assertContains($mine->id, $seen);
        $this->assertContains($theirs->id, $seen);
    }

    // ── The trash ────────────────────────────────────────────────────────────

    /** ⚠️ THE BYTES OUTLIVE THE TRASH: the row can be restored. */
    public function test_a_deletion_is_soft_and_reversible(): void
    {
        $media = $this->media();
        $media->delete();

        $this->assertNull(Media::query()->find($media->id));
        $this->assertNotNull(Media::query()->withTrashed()->find($media->id));

        $media->restore();
        $this->assertNotNull(Media::query()->find($media->id));
    }

    // ── The exposed identifier ───────────────────────────────────────────────

    /** ⚠️ A SEQUENTIAL IDENTIFIER IN A URL IS AN INVITATION TO ENUMERATE. */
    public function test_the_exposed_identifier_is_not_the_database_one(): void
    {
        $media = $this->media();

        $this->assertSame('uuid', $media->getRouteKeyName());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $media->uuid
        );
    }
}
