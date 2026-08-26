<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Contracts\QuotaPolicy;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\BrowseQuery;

/**
 * THE READ API — browsing, showing, measuring.
 *
 * ⚠️ THIS FILE WATCHES WHAT COMES OUT AS CLOSELY AS WHAT IS ASKED FOR. A JSON resource is a
 * boundary: whatever is let through by inattention — a storage path, a checksum, a scope key —
 * cannot be taken back, because clients will have read it.
 */
class BrowseApiTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $current = null;

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-browse';
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);

        /*
         * ⚠️ A REAL LOCAL DISK, NOT `Storage::fake()`. The fake disk installs a temporary-URL
         * builder of its own: it CLAIMS to be able to sign, and the URLs returned by the
         * resources would never go through the package's routes. The bench would then certify a
         * URL shape production does not use.
         */
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-browse',
            'serve' => false,
            'throw' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['files']->deleteDirectory($this->root());
        $this->app['files']->ensureDirectoryExists($this->root());

        self::$current = 'org:a';

        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return BrowseApiTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', BrowseApiTest::key());
            }
        });
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->root());

        parent::tearDown();
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function media(array $attributes = []): Media
    {
        $media = Media::create(array_merge([
            'disk' => 'media',
            'path' => '2026/08/'.uniqid('f', true).'.pdf',
            'name' => 'Report',
            'file_name' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 1024,
            'checksum' => str_repeat('c', 64),
        ], $attributes));

        Storage::disk('media')->put($media->path, 'bytes');

        return $media;
    }

    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return $this->app->make(CreateFolder::class)($name, $parent);
    }

    // ── What comes out ───────────────────────────────────────────────────────

    public function test_the_resource_lets_out_neither_path_nor_checksum(): void
    {
        $media = $this->media();

        $body = $this->getJson('/media/'.$media->uuid)->assertOk()->json('data');

        foreach (['disk', 'path', 'checksum', 'scope_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body);
        }

        $this->assertSame($media->uuid, $body['id']);
        $this->assertSame('Report', $body['name']);
        $this->assertSame('application/pdf', $body['mime_type']);
    }

    public function test_the_resource_carries_the_urls(): void
    {
        $media = $this->media();

        $body = $this->getJson('/media/'.$media->uuid)->assertOk()->json('data');

        $this->assertStringContainsString($media->uuid.'/file', $body['url']);
        $this->assertStringContainsString($media->uuid.'/download', $body['download_url']);
        $this->assertNull($body['thumbnail_url']);
    }

    public function test_the_folder_key_is_always_present_even_at_the_root(): void
    {
        /*
         * ⚠️ PRESENT AS `null`, NOT ABSENT. A key that disappears stops the client telling "at
         * the root" from "the server did not say".
         */
        $body = $this->getJson('/media/'.$this->media()->uuid)->assertOk()->json('data');

        $this->assertArrayHasKey('folder_id', $body);
        $this->assertNull($body['folder_id']);
    }

    public function test_a_foreign_media_returns_404(): void
    {
        self::$current = 'org:b';
        $foreign = $this->media();
        self::$current = 'org:a';

        $this->getJson('/media/'.$foreign->uuid)->assertNotFound();
    }

    // ── Browsing ─────────────────────────────────────────────────────────────

    public function test_the_root_only_shows_what_is_at_the_root(): void
    {
        /*
         * ⚠️ "AT THE ROOT" IS NOT "EVERYWHERE". Confusing them displays the whole library
         * flattened as soon as the screen opens.
         */
        $folder = $this->folder('Invoices');
        $this->media();
        $this->media(['folder_id' => $folder->getKey()]);

        $body = $this->getJson('/media')->assertOk()->json();

        $this->assertCount(1, $body['data']['media']);
        $this->assertCount(1, $body['data']['folders']);
        $this->assertNull($body['data']['folder']);
    }

    public function test_a_folder_shows_its_content_and_its_breadcrumbs(): void
    {
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);
        $this->media(['folder_id' => $child->getKey()]);

        $body = $this->getJson('/media?folder='.$child->uuid)->assertOk()->json('data');

        $this->assertCount(1, $body['media']);
        $this->assertSame('Child', $body['folder']['name']);
        $this->assertSame(['Root', 'Child'], array_column($body['breadcrumbs'], 'name'));
    }

    public function test_a_foreign_folder_returns_404(): void
    {
        self::$current = 'org:b';
        $foreign = $this->folder('Private');
        self::$current = 'org:a';

        $this->getJson('/media?folder='.$foreign->uuid)->assertNotFound();
    }

    // ── Pagination ───────────────────────────────────────────────────────────

    public function test_the_default_page_is_the_first(): void
    {
        /*
         * ⚠️ THE BUG WE REFUSE TO REPEAT. The original module used the NUMBER of items per page
         * as its default page: a call without parameters skipped 1,560 files and returned an
         * empty list to whoever opened the screen.
         */
        for ($i = 0; $i < 5; $i++) {
            $this->media();
        }

        $meta = $this->getJson('/media?per_page=2')->assertOk()->json('meta');

        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(5, $meta['total']);
        $this->assertSame(3, $meta['last_page']);
    }

    public function test_the_page_size_is_capped(): void
    {
        $this->media();

        $meta = $this->getJson('/media?per_page=100000')->assertOk()->json('meta');

        $this->assertSame(100, $meta['per_page']);
    }

    public function test_an_absurd_page_is_brought_back_to_the_first(): void
    {
        /*
         * ⚠️ THE ASSERTION ACTS ON THE OBJECT, NOT ON THE RESPONSE, AND THAT IS DELIBERATE.
         * Laravel's paginator also bounds negative pages: over HTTP, removing our normalisation
         * changes NOTHING — the mutation survives, and the test proves nothing. Yet the
         * guarantee is ours: `BrowseQuery` is a value object whose `->page` other callers will
         * read without ever touching a paginator.
         */
        $this->assertSame(1, BrowseQuery::fromInput(['page' => -4])->page);
        $this->assertSame(1, BrowseQuery::fromInput(['page' => 0])->page);

        $this->media();
        $this->assertSame(1, $this->getJson('/media?page=-4')->assertOk()->json('meta.current_page'));
    }

    // ── Filtering and sorting ────────────────────────────────────────────────

    public function test_the_search_acts_on_the_displayed_name(): void
    {
        $this->media(['name' => 'July invoice']);
        $this->media(['name' => 'Annual report']);

        $body = $this->getJson('/media?search=invoice')->assertOk()->json('data.media');

        $this->assertCount(1, $body);
        $this->assertSame('July invoice', $body[0]['name']);
    }

    public function test_a_wildcard_in_the_search_does_not_act_as_a_wildcard(): void
    {
        /*
         * ⚠️ THIS TEST HAD TO BE CHOSEN, NOT MERELY WRITTEN. Searching for `%` alone proves
         * nothing: the term becomes empty, the search is abandoned, and EVERYTHING comes back —
         * exactly what the wildcard would have produced. It takes a pattern that finds nothing
         * once the wildcard is stripped, and would find something if it were passed through:
         * "I%e" matches no name, but `%I%e%` would match "July invoice".
         */
        $this->media(['name' => 'Invoice']);
        $this->media(['name' => 'Report']);

        $this->getJson('/media?search=I%25e')->assertOk()->assertJsonCount(0, 'data.media');
    }

    /**
     * ⚠️ A SEARCH LOOKS THROUGH THE WHOLE LIBRARY, NOT THE FOLDER IN FRONT OF YOU. Kept inside
     * the level, a term typed at the root found only what happened to be lying at the root — and
     * the files anybody goes looking for are the ones that were filed away. The search answered
     * "nothing" for everything tidy, which reads as the file being gone.
     */
    public function test_a_search_looks_through_the_whole_library(): void
    {
        $archive = $this->folder('Archive');
        $filed = $this->media(['name' => 'July invoice', 'folder_id' => $archive->getKey()]);
        $this->media(['name' => 'Annual report']);

        $found = $this->getJson('/media?search=invoice')->assertOk()->json('data.media');

        $this->assertSame([$filed->uuid], array_column($found, 'id'));
    }

    /**
     * ⚠️ AND FROM INSIDE A FOLDER JUST THE SAME. Somebody standing in one folder searching for a
     * name is looking for the file they cannot see; answering only with what is already on
     * screen is answering with what they did not ask for.
     */
    public function test_a_search_made_inside_a_folder_still_looks_outside_it(): void
    {
        $here = $this->folder('Here');
        $elsewhere = $this->folder('Elsewhere');
        $wanted = $this->media(['name' => 'Contract', 'folder_id' => $elsewhere->getKey()]);

        $found = $this->getJson('/media?folder='.$here->uuid.'&search=contract')
            ->assertOk()
            ->json('data.media');

        $this->assertSame([$wanted->uuid], array_column($found, 'id'));
    }

    /**
     * ⚠️ THE FOLDERS OBEY THE TERM TOO. Left unfiltered they were the one half of the listing
     * the search did not reach: a level holding twelve folders returned all twelve ahead of the
     * results, and with a page counting a folder as an item they took the room the matches were
     * supposed to have.
     */
    public function test_the_folders_obey_the_search_too(): void
    {
        $wanted = $this->folder('Invoices');
        $this->folder('Reports');

        $found = $this->getJson('/media?search=invoi')->assertOk()->json('data.folders');

        $this->assertSame([$wanted->uuid], array_column($found, 'id'));
    }

    /** ⚠️ WHEREVER IT SITS: the folder somebody is trying to find again is not the one in front
     * of them, so the nesting is no more a boundary for a folder than it is for a file. */
    public function test_a_folder_is_found_however_deep_it_sits(): void
    {
        $archive = $this->folder('Archive');
        $year = $this->folder('2024', $archive);
        $wanted = $this->folder('Invoices', $year);

        $found = $this->getJson('/media?search=invoi')->assertOk()->json('data.folders');

        $this->assertSame([$wanted->uuid], array_column($found, 'id'));
    }

    /**
     * ⚠️ A SEARCH IS NOT A WAY OUT OF THE TRASH, IN EITHER DIRECTION. Both listings hold names
     * that match; each has to answer with its own. Comparing counts would not have caught it —
     * one on either side is what a listing that ignored the trash returned as well.
     */
    public function test_a_search_in_the_trash_answers_with_the_trash(): void
    {
        $thrownFile = $this->media(['name' => 'Invoice']);
        $thrownFile->delete();
        $keptFile = $this->media(['name' => 'Invoice']);

        $thrownFolder = $this->folder('Invoices');
        $thrownFolder->delete();
        $keptFolder = $this->folder('Invoices');

        $live = $this->getJson('/media?search=invoic')->assertOk()->json('data');
        $trash = $this->getJson('/media?search=invoic&trashed=1')->assertOk()->json('data');

        $this->assertSame([$keptFile->uuid], array_column($live['media'], 'id'));
        $this->assertSame([$keptFolder->uuid], array_column($live['folders'], 'id'));

        $this->assertSame([$thrownFile->uuid], array_column($trash['media'], 'id'));
        $this->assertSame([$thrownFolder->uuid], array_column($trash['folders'], 'id'));
    }

    /**
     * ⚠️ AND IT REACHES WHAT THE TRASH ONLY SHOWS ONE LEVEL DOWN. At the top of the trash, a
     * folder whose parent was thrown away too is deliberately not listed — it is reached by
     * walking into that parent, as in the library. A search is the one thing that has to see
     * past that rule, or a branch thrown away whole is searchable only at its root.
     */
    public function test_a_search_finds_a_trashed_folder_inside_a_trashed_folder(): void
    {
        $parent = $this->folder('Archive');
        $child = $this->folder('Invoices', $parent);
        $parent->delete();
        $child->delete();

        $top = $this->getJson('/media?trashed=1')->assertOk()->json('data.folders');
        $found = $this->getJson('/media?trashed=1&search=invoi')->assertOk()->json('data.folders');

        $this->assertSame([$parent->uuid], array_column($top, 'id'));
        $this->assertSame([$child->uuid], array_column($found, 'id'));
    }

    public function test_the_family_filter_is_honoured(): void
    {
        $this->media(['type' => 'image', 'mime_type' => 'image/png']);
        $this->media(['type' => 'video', 'mime_type' => 'video/mp4']);

        $this->getJson('/media?types=image')->assertOk()->assertJsonCount(1, 'data.media');
    }

    public function test_an_unknown_sort_never_reaches_the_query(): void
    {
        /*
         * ⚠️ A COLUMN NAME THAT DOES NOT EXIST IN AN `ORDER BY` BRINGS THE QUERY DOWN. This test
         * therefore does not check an interface courtesy: it checks that the client's value
         * never reaches the engine.
         */
        $this->media();

        $this->getJson('/media?sort=column_that_does_not_exist')->assertOk();
    }

    public function test_the_library_cannot_be_sorted_by_checksum(): void
    {
        /*
         * ⚠️ THIS IS THE REASON THE ALLOW-LIST EXISTS, AND IT IS NOT THEORETICAL. Sorting on
         * `checksum` and walking the pages reveals whether content you already hold exists
         * elsewhere in the product — at another customer's, in a folder you cannot see. That is
         * a comparison no user is entitled to make.
         *
         * ⚠️ THE CHECKSUMS RUN AGAINST THE DATES ON PURPOSE. Given the same order both ways, a
         * listing that obeyed `checksum` and one that ignored it return the same two rows in the
         * same sequence, and the assertion holds whatever the query did. Here the two answers
         * are opposites, so only one of them can pass.
         *
         * ⚠️ AND THE DATES ARE WRITTEN DOWN RATHER THAN LEFT TO THE CLOCK. `direction` is parsed
         * on its own and survives the rejected column, so the fallback is `created_at ASC` — on
         * which two rows created in the same second tie, leaving the order to the tiebreaker and
         * the test to luck. It passed for as long as both inserts landed inside one second, and
         * failed the run where they straddled a boundary — on one job of five, on a commit that
         * had passed everywhere minutes before.
         */
        $first = $this->media([
            'name' => 'First',
            'checksum' => str_repeat('b', 64),
            'created_at' => now()->subMinute(),
        ]);

        $second = $this->media([
            'name' => 'Second',
            'checksum' => str_repeat('a', 64),
            'created_at' => now(),
        ]);

        $order = array_column(
            $this->getJson('/media?sort=checksum&direction=asc')->assertOk()->json('data.media'),
            'id'
        );

        /* Ascending checksum would put `$second` first; the date it fell back to puts `$first`. */
        $this->assertSame([$first->uuid, $second->uuid], $order);
    }

    public function test_the_trash_has_to_be_asked_for_explicitly(): void
    {
        /*
         * ⚠️ WE COMPARE IDENTITIES, NOT COUNTS. A test counting "one on each side" stayed green
         * when the trash was ignored: the normal listing returned the right file, and the
         * "trash" listing returned the SAME one — one item on either side, two correct counts,
         * one false property.
         */
        $thrown = $this->media();
        $thrown->delete();
        $kept = $this->media();

        $this->assertSame(
            [$kept->uuid],
            array_column($this->getJson('/media')->assertOk()->json('data.media'), 'id')
        );

        $this->assertSame(
            [$thrown->uuid],
            array_column($this->getJson('/media?trashed=1')->assertOk()->json('data.media'), 'id')
        );
    }

    /**
     * ⚠️ THE TOP OF THE TRASH LISTED NOTHING AT ALL ON THIS BACKEND, and the bench that was
     * supposed to prove it ran on the other one. The rule — "what was thrown away, minus what is
     * reached by walking into a thrown-away parent" — was written as `parent_id NOT IN (…)`,
     * which is UNKNOWN rather than TRUE for a folder at the root, because this schema writes
     * that root as NULL. SQL discards UNKNOWN, so the trash was empty of folders and a branch
     * thrown away could not be put back. The preset that writes the root as 0 answered
     * correctly throughout: 0 is a value, and a value compares.
     */
    public function test_a_folder_thrown_away_at_the_root_is_at_the_top_of_the_trash(): void
    {
        $folder = $this->folder('Archive');
        $folder->delete();

        $listed = $this->getJson('/media?trashed=1')->assertOk()->json('data.folders');

        $this->assertSame([$folder->uuid], array_column($listed, 'id'));
    }

    /**
     * ⚠️ AND THE OTHER HALF OF THE SAME RULE STILL HOLDS. A folder whose parent went to the
     * trash with it belongs inside that parent, not beside it; listing both at the top would
     * show the same branch twice and make "restore" ambiguous. Without this, the fix above could
     * have been "list everything trashed" — which passes the test above and loses the nesting.
     */
    public function test_a_folder_inside_a_trashed_folder_is_not_at_the_top_of_the_trash(): void
    {
        $parent = $this->folder('Archive');
        $child = $this->folder('Invoices', $parent);
        $parent->delete();
        $child->delete();

        $listed = $this->getJson('/media?trashed=1')->assertOk()->json('data.folders');

        $this->assertSame([$parent->uuid], array_column($listed, 'id'));

        $inside = $this->getJson('/media?trashed=1&folder='.$parent->uuid)
            ->assertOk()
            ->json('data.folders');

        $this->assertSame([$child->uuid], array_column($inside, 'id'));
    }

    /**
     * ⚠️ AND THE TWO CONDITIONS ARE ONE GROUP, WHICH IS NOT A MATTER OF STYLE. Written flat,
     * the `OR` binds against everything before it — the "only what is deleted" filter
     * included — so `deleted_at IS NOT NULL AND parent_id IS NULL OR parent_id NOT IN (…)`
     * reads as two alternatives, and the second one is true for any LIVE folder that sits under
     * a live parent. The trash then lists folders nobody threw away, offering to restore what
     * was never gone.
     *
     * ⚠️ THE DECOR IS WHAT MAKES THIS BENCH WORK, AND IT TOOK A MUTATION TO FIND IT. A live
     * folder at the ROOT is excluded either way — its NULL parent makes the loose condition
     * UNKNOWN too — so a decor of root folders certifies the grouping while proving nothing
     * about it. It takes a live folder with a live parent.
     */
    public function test_the_trash_lists_nothing_that_is_still_in_the_library(): void
    {
        $parent = $this->folder('Archive');
        $this->folder('Invoices', $parent);

        $thrown = $this->folder('Old');
        $thrown->delete();

        $listed = $this->getJson('/media?trashed=1')->assertOk()->json('data.folders');

        $this->assertSame([$thrown->uuid], array_column($listed, 'id'));
    }

    // ── The quota ────────────────────────────────────────────────────────────

    public function test_an_unlimited_quota_says_so(): void
    {
        $body = $this->getJson('/media/quota')->assertOk()->json('data');

        $this->assertNull($body['limit']);
        $this->assertTrue($body['unlimited']);
    }

    public function test_a_bounded_quota_returns_what_is_left(): void
    {
        $this->app->singleton(QuotaPolicy::class, fn () => new class implements QuotaPolicy
        {
            public function limitInBytes(?string $scopeKey): ?int
            {
                return 1000;
            }

            public function usedInBytes(?string $scopeKey): int
            {
                return 400;
            }

            public function allows(?string $scopeKey, int $incomingBytes): bool
            {
                return true;
            }
        });

        $body = $this->getJson('/media/quota')->assertOk()->json('data');

        $this->assertSame(1000, $body['limit']);
        $this->assertSame(400, $body['used']);
        $this->assertSame(600, $body['remaining']);
        $this->assertFalse($body['unlimited']);
    }

    public function test_the_quota_is_not_read_as_a_media_identifier(): void
    {
        /*
         * ⚠️ ROUTE ORDER IS A BEHAVIOUR. Declared after `{media}`, the `quota` URL would be read
         * as an identifier, would not resolve, and would return a 404 nothing would explain.
         */
        $this->getJson('/media/quota')->assertOk()->assertJsonStructure(['data' => ['limit']]);
    }
}
