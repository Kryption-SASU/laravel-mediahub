<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\ServerRuntime;
use Kryption\MediaHub\Tests\Fixtures\ZipReader;
use Kryption\MediaHub\Tests\TestCase;

/**
 * ARCHIVES — getting several things out at once.
 *
 * ⚠️ THIS FILE MOSTLY CHECKS WHAT HAPPENS WHEN THINGS GO WRONG, because that is where the
 * original module failed in silence: its ZIP closed empty or partial and downloaded normally. An
 * incomplete archive is indistinguishable from a complete one — unless it says so itself.
 */
class ArchiveApiTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $current = null;

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-archive';
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);

        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-archive',
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
                return ArchiveApiTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', ArchiveApiTest::key());
            }
        });
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->root());

        /* ⚠️ ARMING THE TIMER LEAVES IT ARMED. A bench that sets thirty seconds and walks away
         * has armed it for every test that follows, and the suite then dies somewhere else
         * entirely — which reads as a flaky test rather than as this one. */
        ini_set('max_execution_time', '0');

        parent::tearDown();
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function media(array $attributes = [], string $contents = 'contents'): Media
    {
        $media = Media::create(array_merge([
            'disk' => 'media',
            'path' => 'objects/'.uniqid('o', true).'.bin',
            'name' => 'Report',
            'file_name' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => strlen($contents),
        ], $attributes));

        $this->app['filesystem']->disk('media')->put($media->path, $contents);

        return $media;
    }

    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return $this->app->make(CreateFolder::class)($name, $parent);
    }

    private function archive(array $payload): ZipReader
    {
        $response = $this->post('/media/archive', $payload);

        $response->assertOk();

        return ZipReader::from($response->streamedContent());
    }

    // ── What the archive contains ────────────────────────────────────────────

    public function test_the_chosen_files_sit_at_the_root_of_the_archive(): void
    {
        /*
         * ⚠️ THE PERSON CHOSE FILES, NOT A LOCATION. Reproducing the tree for a one-off
         * selection forces them to dig through three levels to find two files they had just
         * pointed at.
         */
        /*
         * ⚠️ CREATED IN THE REVERSE ORDER OF THEIR NAMES, AND THAT IS THE WHOLE POINT OF THIS
         * TEST. With "Balance" created first, the expected order would coincide with the
         * creation order: a sort by identifier, or no sort at all, would pass just as well.
         * Resolution returns the models in database order anyway — so, on a random key, in a
         * random order: the same request twice would give two different archives, and the
         * namesake suffix would move from one file to the other.
         */
        $second = $this->media(['name' => 'Invoice'], 'two');
        $first = $this->media(['name' => 'Balance'], 'one');

        $zip = $this->archive(['media' => [$first->uuid, $second->uuid]]);

        $this->assertSame(['Balance.pdf', 'Invoice.pdf'], $zip->names());
        $this->assertSame('one', $zip->contents('Balance.pdf'));
        $this->assertSame('two', $zip->contents('Invoice.pdf'));
    }

    public function test_a_folder_keeps_its_tree(): void
    {
        /*
         * ⚠️ AND IT KEEPS ITS OWN NAME IN FRONT. Without it, the content spills into the root of
         * the archive and mixes with the rest of the selection.
         */
        $root = $this->folder('Clients');
        $child = $this->folder('Durand', $root);

        $this->media(['name' => 'Quote', 'folder_id' => $root->getKey()], 'a');
        $this->media(['name' => 'Contract', 'folder_id' => $child->getKey()], 'b');

        $zip = $this->archive(['folders' => [$root->uuid]]);

        $this->assertSame(['clients/Quote.pdf', 'clients/durand/Contract.pdf'], $zip->names());
        $this->assertSame('b', $zip->contents('clients/durand/Contract.pdf'));
    }

    public function test_a_chosen_subfolder_does_not_drag_its_ancestors_along(): void
    {
        $root = $this->folder('Clients');
        $child = $this->folder('Durand', $root);
        $this->media(['name' => 'Contract', 'folder_id' => $child->getKey()]);

        $zip = $this->archive(['folders' => [$child->uuid]]);

        $this->assertSame(['durand/Contract.pdf'], $zip->names());
    }

    public function test_a_file_picked_twice_goes_in_only_once(): void
    {
        $folder = $this->folder('Clients');
        $media = $this->media(['name' => 'Contract', 'folder_id' => $folder->getKey()]);

        $zip = $this->archive(['media' => [$media->uuid], 'folders' => [$folder->uuid]]);

        $this->assertCount(1, $zip->names());
    }

    public function test_two_identical_names_do_not_overwrite_each_other(): void
    {
        /*
         * ⚠️ NOTHING FORBIDS TWO FILES WITH THE SAME DISPLAYED NAME IN THE SAME PLACE — only the
         * name on disk is unique. The ZIP format accepts two entries with the same name, and the
         * extractor overwrites one: a file disappears with no error, neither when building nor
         * when extracting.
         */
        $first = $this->media(['name' => 'Contract'], 'one');
        $second = $this->media(['name' => 'Contract'], 'two');

        $zip = $this->archive(['media' => [$first->uuid, $second->uuid]]);

        $this->assertSame(['Contract.pdf', 'Contract-2.pdf'], $zip->names());
        $this->assertSame('one', $zip->contents('Contract.pdf'));
        $this->assertSame('two', $zip->contents('Contract-2.pdf'));
    }

    public function test_a_name_that_climbs_out_of_a_folder_is_neutralised(): void
    {
        /*
         * ⚠️ THE "ZIP SLIP". We are not on the receiving end here — we would BUILD it: on
         * extraction, the entry would land outside the destination folder, on the machine of
         * whoever opens the archive.
         */
        $media = $this->media(['name' => '../../passwd']);

        $zip = $this->archive(['media' => [$media->uuid]]);

        $this->assertSame(['passwd.pdf'], $zip->names());
    }

    // ── What is missing, and how we say it ───────────────────────────────────

    public function test_a_missing_object_is_recorded_inside_the_archive(): void
    {
        /*
         * ⚠️ THIS IS THE ORIGINAL'S EXACT DEFECT. It added its files with a local path derived
         * from a FULL URL: `addFile` returned `false`, nobody checked it, and the archive closed
         * partial while downloading normally. Since the status code is already gone by the time
         * anyone notices, the only place left to say it is the archive itself.
         */
        $present = $this->media(['name' => 'Present'], 'here');
        $missing = $this->media(['name' => 'Missing']);

        $this->app['filesystem']->disk('media')->delete($missing->path);

        $zip = $this->archive(['media' => [$present->uuid, $missing->uuid]]);

        $this->assertTrue($zip->has('Present.pdf'));
        $this->assertFalse($zip->has('Missing.pdf'));
        $this->assertTrue($zip->has('MISSING.txt'));
        $this->assertStringContainsString('Missing.pdf', $zip->contents('MISSING.txt'));
    }

    public function test_a_complete_archive_carries_no_report(): void
    {
        $zip = $this->archive(['media' => [$this->media()->uuid]]);

        $this->assertFalse($zip->has('MISSING.txt'));
    }

    // ── Refusals, while they are still possible ──────────────────────────────

    public function test_a_selection_without_a_single_file_is_refused(): void
    {
        /*
         * ⚠️ AN EMPTY ARCHIVE DOWNLOADS AND OPENS: it reads as a success. An empty folder must
         * be reported before the first byte, while an HTTP status code is still possible.
         */
        $this->postJson('/media/archive', ['folders' => [$this->folder('Empty')->uuid]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'archive_empty');
    }

    public function test_too_many_files_is_refused(): void
    {
        $this->app['config']->set('mediahub.archives.max_files', 1);

        $this->postJson('/media/archive', [
            'media' => [$this->media()->uuid, $this->media()->uuid],
        ])->assertStatus(422)->assertJsonPath('reason', 'archive_too_many_files');
    }

    /**
     * ⚠️ WHAT THE CONFIGURATION PERMITS IS NOT WHAT THE MACHINE CAN FINISH, and the second bound
     * exists because the first cannot see the difference. Past the first byte the 200 is gone:
     * an archive cut off halfway downloads, opens, and is missing files, with nothing anywhere
     * to say so — not the log, which records a completed request, and not the person, who has a
     * file.
     */
    public function test_an_archive_beyond_what_this_machine_can_finish_is_refused(): void
    {
        /* Generous policy, tiny capacity: one second at one byte a second. */
        $this->app['config']->set('mediahub.archives.max_bytes', 1024 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.time_budget', 1);
        $this->app['config']->set('mediahub.archives.throughput', 1);

        $this->postJson('/media/archive', ['media' => [$this->media([], 'far too long')->uuid]])
            ->assertStatus(422)
            /* ⚠️ A DIFFERENT REASON FROM "TOO LARGE", because they call for different actions:
             * one says the selection exceeds a policy somebody chose, the other says the policy
             * exceeds the machine, and the fix is in `php-fpm.conf` rather than the selection. */
            ->assertJsonPath('reason', 'archive_beyond_capacity');
    }

    /** ⚠️ AND A DECLARED BUDGET LETS THE SAME SELECTION THROUGH, which is what makes the refusal
     * above a bound rather than a wall. */
    public function test_a_declared_budget_lets_the_same_archive_through(): void
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 1024 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.time_budget', 600);

        $this->postJson('/media/archive', ['media' => [$this->media([], 'far too long')->uuid]])
            ->assertOk();
    }

    /**
     * ⚠️ "NO LIMIT" IN THE CONFIGURATION IS STILL NOT INFINITY. It means the package imposes none
     * of its own; read as "the machine can send anything", it is what lets a two-hour archive
     * start and be cut off at minute four.
     */
    public function test_an_unlimited_policy_is_still_bounded_by_the_machine(): void
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 0);
        $this->app['config']->set('mediahub.archives.time_budget', 1);
        $this->app['config']->set('mediahub.archives.throughput', 1);

        $this->postJson('/media/archive', ['media' => [$this->media([], 'far too long')->uuid]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'archive_beyond_capacity');
    }

    public function test_an_archive_that_is_too_heavy_is_refused(): void
    {
        /*
         * ⚠️ THE BOUND DEFENDS NEITHER MEMORY NOR DISK — streaming takes care of that. It
         * defends against TIME: an archive cut off by `max_execution_time` is truncated, and a
         * truncated archive downloads and opens normally.
         */
        $this->app['config']->set('mediahub.archives.max_bytes', 3);

        $this->postJson('/media/archive', ['media' => [$this->media([], 'far too long')->uuid]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'archive_too_large');
    }

    // ── Compression ──────────────────────────────────────────────────────────

    public function test_what_is_already_compressed_is_not_recompressed(): void
    {
        /*
         * ⚠️ RUNNING A JPEG THROUGH `deflate` COSTS CPU TIME FOR NO GAIN — and on a
         * multi-gigabyte archive, that time is exactly what makes the request time out, and
         * therefore what truncates the archive.
         */
        $image = $this->media(['name' => 'Photo', 'mime_type' => 'image/png', 'extension' => 'png']);
        $text = $this->media(['name' => 'Notes', 'mime_type' => 'text/plain', 'extension' => 'txt']);

        $zip = $this->archive(['media' => [$image->uuid, $text->uuid]]);

        $this->assertSame(0, $zip->method('Photo.png'), 'an image must be stored as it is');
        $this->assertSame(8, $zip->method('Notes.txt'), 'text must be deflated');
    }

    // ── Headers ──────────────────────────────────────────────────────────────

    public function test_the_response_is_an_attachment_with_no_announced_length(): void
    {
        /*
         * ⚠️ NO `Content-Length`: the compressed size is only known once the archive has been
         * written. Announcing a wrong one would make the client cut the connection at the wrong
         * byte — that is, a truncated archive that looks complete.
         */
        $response = $this->post('/media/archive', ['media' => [$this->media()->uuid]]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertNull($response->headers->get('Content-Length'));
    }

    // ── Scoping and permissions ──────────────────────────────────────────────

    public function test_a_foreign_media_returns_404_and_discloses_nothing(): void
    {
        $mine = $this->media();

        self::$current = 'org:b';
        $foreign = $this->media();
        self::$current = 'org:a';

        $this->postJson('/media/archive', ['media' => [$mine->uuid, $foreign->uuid]])
            ->assertNotFound();
    }

    public function test_a_policy_refusing_downloads_blocks_the_archive(): void
    {
        $media = $this->media();

        $this->refuseDownloads();

        $this->postJson('/media/archive', ['media' => [$media->uuid]])->assertForbidden();
    }

    public function test_the_content_of_a_folder_is_authorised_too(): void
    {
        /*
         * ⚠️ A FOLDER IS NOT AN OBJECT ONE DOWNLOADS: it is a list of files, and it is the files
         * that come out. Checking only the folder would turn picking a parent into a one-click
         * bypass.
         */
        $folder = $this->folder('Clients');
        $this->media(['folder_id' => $folder->getKey()]);

        $this->refuseDownloads();

        $this->postJson('/media/archive', ['folders' => [$folder->uuid]])->assertForbidden();
    }

    public function test_the_download_refusal_also_holds_for_a_single_file(): void
    {
        /*
         * ⚠️ WITHOUT THIS PERMISSION, A POLICY COULD FORBID MODIFYING A MEDIA AND LET ANYONE
         * DOWNLOAD IT. The archive would then have been one more path to a door already open.
         */
        $media = $this->media();

        $this->refuseDownloads();

        $this->get('/media/'.$media->uuid.'/download')->assertForbidden();
        $this->get('/media/'.$media->uuid.'/file')->assertForbidden();
    }

    public function test_the_record_stays_readable_when_downloading_is_refused(): void
    {
        /*
         * ⚠️ THE COUNTER-EXAMPLE. Without it, a broken policy refusing EVERYTHING would pass the
         * three tests above, and this file would certify a failure.
         */
        $media = $this->media();

        $this->refuseDownloads();

        $this->getJson('/media/'.$media->uuid)->assertOk();
    }

    // ── The time the stream gives itself ─────────────────────────────────────

    /**
     * ⚠️ WAITING ON STORAGE DOES NOT COUNT AGAINST `max_execution_time`, BUT COMPRESSING DOES.
     * Measured: a script blocked on a pipe outlived a two-second limit by fifteen, while the
     * same limit killed a busy loop at 2.1. So deflating a few gigabytes is exactly the kind of
     * work that reaches the limit — and it reaches it after the 200 and the first bytes have
     * gone, which is the truncated archive this whole action exists to avoid.
     *
     * ⚠️ AND IT IS OBSERVABLE, which is why this is a bench rather than a comment.
     * `set_time_limit(0)` moves `max_execution_time` to zero, so what the stream did to its own
     * runtime can simply be read back afterwards.
     */
    public function test_the_stream_buys_itself_the_time_to_finish(): void
    {
        $media = $this->media();

        ini_set('max_execution_time', '30');

        $this->archive(['media' => [$media->uuid]]);

        $this->assertSame('0', ini_get('max_execution_time'));
    }

    /**
     * ⚠️ AND WHERE IT CANNOT, IT DOES NOT PRETEND TO. `disable_functions` taking `set_time_limit`
     * away is ordinary on shared hosting. What matters is that the health report and the stream
     * read the same answer from the same place: a report promising the limit is lifted, beside a
     * stream that silently could not, sends somebody looking for the fault everywhere except
     * where it is.
     */
    public function test_it_does_not_lift_a_limit_it_was_told_it_cannot(): void
    {
        $this->app->instance(ServerRuntime::class, new ServerRuntime(PHP_SAPI, false));

        $media = $this->media();

        ini_set('max_execution_time', '30');

        $this->archive(['media' => [$media->uuid]]);

        $this->assertSame('30', ini_get('max_execution_time'));
    }

    private function refuseDownloads(): void
    {
        $this->app->singleton(AccessPolicy::class, fn () => new class implements AccessPolicy
        {
            public function browse(): bool
            {
                return true;
            }

            public function upload(): bool
            {
                return true;
            }

            public function download(Media $media): bool
            {
                return false;
            }

            public function modify(Media|MediaFolder $item): bool
            {
                return true;
            }

            public function destroy(Media|MediaFolder $item): bool
            {
                return true;
            }
        });
    }
}
