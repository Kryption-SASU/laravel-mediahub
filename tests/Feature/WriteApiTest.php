<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE WRITE API — uploading, changing, trashing, purging.
 *
 * ⚠️ THIS IS THE HALF OF THE API WHERE THE ORIGINAL MODULE GAVE WAY. Its listings were scoped,
 * its ACTIONS were not: a posted identifier was enough to delete, rename or make public another
 * customer's file, through a single entry point of twelve branches, eight of which destroyed
 * something. This file checks that every route refuses separately.
 */
class WriteApiTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $current = null;

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-write';
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);

        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-write',
            'serve' => false,
            'throw' => false,
        ]);

        $app['config']->set('mediahub.storage.disk', 'media');
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
                return WriteApiTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', WriteApiTest::key());
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

    private function image(string $name = 'photo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, SampleImages::bytes('image/png'));

        return new UploadedFile($path, $name, 'image/png', null, true);
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
            'size' => 5,
        ], $attributes));

        $this->app['filesystem']->disk('media')->put($media->path, 'bytes');

        return $media;
    }

    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return $this->app->make(CreateFolder::class)($name, $parent);
    }

    private function forbidEverything(): void
    {
        $this->app->singleton(AccessPolicy::class, fn () => new class implements AccessPolicy
        {
            public function browse(): bool
            {
                return true;
            }

            public function upload(): bool
            {
                return false;
            }

            public function download(Media $media): bool
            {
                return false;
            }

            public function modify(Media|MediaFolder $item): bool
            {
                return false;
            }

            public function destroy(Media|MediaFolder $item): bool
            {
                return false;
            }
        });
    }

    // ── Uploading ────────────────────────────────────────────────────────────

    public function test_an_upload_creates_the_row_and_writes_the_bytes(): void
    {
        $response = $this->post('/media', ['files' => [$this->image()]]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'data');

        $media = Media::query()->sole();

        $this->assertSame('image/png', $media->mime_type);
        $this->assertTrue($this->app['filesystem']->disk('media')->exists($media->path));
    }

    public function test_an_upload_into_a_folder_records_the_folder(): void
    {
        $folder = $this->folder('Photos');

        $this->post('/media', ['files' => [$this->image()], 'folder' => $folder->uuid])
            ->assertStatus(201);

        $this->assertSame($folder->getKey(), Media::query()->sole()->folder_id);
    }

    public function test_the_folder_does_not_decide_where_the_bytes_are_filed(): void
    {
        /*
         * ⚠️ OTHERWISE MOVING A FOLDER WOULD BECOME A FILE MIGRATION, on remote storage, with no
         * transaction to cover it.
         */
        $folder = $this->folder('Photos');

        $this->post('/media', ['files' => [$this->image()], 'folder' => $folder->uuid]);

        $this->assertStringNotContainsString('photos', Media::query()->sole()->path);
    }

    public function test_a_refused_file_does_not_stop_the_others(): void
    {
        /*
         * ⚠️ THE DELIBERATE EXCEPTION TO THE BATCH RULE. Elsewhere a batch passes whole or not
         * at all — because it acts on EXISTING objects and a refusal there is a refusal of
         * RIGHT. Here the right is the same for the whole upload; what differs is the nature of
         * the content, and rejecting twenty photographs because of a twenty-first protects
         * nobody.
         */
        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, "#!/bin/sh\necho no\n");
        $intruder = new UploadedFile($path, 'script.sh', 'text/plain', null, true);

        $response = $this->post('/media', ['files' => [$this->image(), $intruder]]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonCount(1, 'errors');
        $this->assertSame(1, Media::query()->count());
    }

    public function test_an_upload_refused_in_full_does_not_report_a_success(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($path, 'nope');
        $intruder = new UploadedFile($path, 'script.sh', 'text/plain', null, true);

        $this->post('/media', ['files' => [$intruder]])->assertStatus(422);

        $this->assertSame(0, Media::query()->count());
    }

    public function test_an_upload_without_a_file_is_refused(): void
    {
        $this->postJson('/media', [])->assertStatus(422);
    }

    public function test_an_upload_into_a_foreign_folder_returns_404(): void
    {
        self::$current = 'org:b';
        $foreign = $this->folder('Private');
        self::$current = 'org:a';

        $this->post('/media', ['files' => [$this->image()], 'folder' => $foreign->uuid])
            ->assertNotFound();

        $this->assertSame(0, Media::query()->count());
    }

    // ── Changing ─────────────────────────────────────────────────────────────

    public function test_renaming_through_the_api_does_not_touch_the_file_name(): void
    {
        $media = $this->media();

        $this->patchJson('/media/'.$media->uuid, ['name' => 'Balance'])->assertOk();

        $this->assertSame('Balance', $media->fresh()->name);
        $this->assertSame('report.pdf', $media->fresh()->file_name);
    }

    public function test_moving_through_the_api_asks_for_the_folder_key(): void
    {
        $media = $this->media();
        $folder = $this->folder('Archives');

        $this->patchJson('/media/'.$media->uuid, ['folder' => $folder->uuid])->assertOk();

        $this->assertSame($folder->getKey(), $media->fresh()->folder_id);
    }

    public function test_a_rename_does_not_detach_the_media_from_its_folder(): void
    {
        /*
         * ⚠️ "MOVE TO THE ROOT" AND "DO NOT MOVE" ARE TOLD APART BY THE PRESENCE OF THE KEY, not
         * by its value. Without that distinction, every rename would send the file back to the
         * root — a move nobody asked for.
         */
        $folder = $this->folder('Archives');
        $media = $this->media(['folder_id' => $folder->getKey()]);

        $this->patchJson('/media/'.$media->uuid, ['name' => 'Balance'])->assertOk();

        $this->assertSame($folder->getKey(), $media->fresh()->folder_id);
    }

    public function test_moving_into_a_foreign_folder_returns_404(): void
    {
        $media = $this->media();

        self::$current = 'org:b';
        $foreign = $this->folder('Private');
        self::$current = 'org:a';

        $this->patchJson('/media/'.$media->uuid, ['folder' => $foreign->uuid])->assertNotFound();

        $this->assertNull($media->fresh()->folder_id);
    }

    public function test_copying_through_the_api_produces_a_second_row(): void
    {
        $media = $this->media();

        /* ⚠️ 201 and not 200: Laravel reads `wasRecentlyCreated` on the returned resource. */
        $this->postJson('/media/'.$media->uuid.'/copy')->assertStatus(201);

        $this->assertSame(2, Media::query()->count());
    }

    // ── Folders ──────────────────────────────────────────────────────────────

    public function test_creating_a_folder(): void
    {
        $this->postJson('/media/folders', ['name' => 'Invoices'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Invoices')
            ->assertJsonPath('data.path', 'invoices');
    }

    public function test_creating_a_folder_without_a_name_is_refused(): void
    {
        $this->postJson('/media/folders', [])->assertStatus(422);
    }

    public function test_renaming_a_folder_rewrites_its_descendants(): void
    {
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);

        $this->patchJson('/media/folders/'.$root->uuid, ['name' => 'Trunk'])->assertOk();

        $this->assertSame('trunk/child', $child->fresh()->path);
    }

    public function test_moving_a_folder_into_its_own_descendants_is_refused(): void
    {
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);

        $this->patchJson('/media/folders/'.$root->uuid, ['parent' => $child->uuid])
            ->assertStatus(422);

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_renaming_a_subfolder_does_not_lift_it_to_the_root(): void
    {
        /*
         * ⚠️ THIS TEST WAS ADDED AFTERWARDS, AND THE REASON NEEDS SAYING. The only rename test
         * acted on a folder ALREADY at the root: lifting it there was a non-event, and the
         * mutation that moved it on every call stayed invisible. A rename that detaches a whole
         * branch from its parent is a disappearance, not a field error.
         */
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);

        $this->patchJson('/media/folders/'.$child->uuid, ['name' => 'Leaf'])->assertOk();

        $this->assertSame($root->getKey(), $child->fresh()->parent_id);
        $this->assertSame('root/leaf', $child->fresh()->path);
    }

    public function test_a_foreign_folder_cannot_be_renamed(): void
    {
        self::$current = 'org:b';
        $foreign = $this->folder('Private');
        self::$current = 'org:a';

        $this->patchJson('/media/folders/'.$foreign->uuid, ['name' => 'Mine'])->assertNotFound();
    }

    // ── The trash ────────────────────────────────────────────────────────────

    public function test_trashing_then_restoring(): void
    {
        $media = $this->media();

        $this->postJson('/media/trash', ['media' => [$media->uuid]])->assertOk();
        $this->assertNull(Media::query()->find($media->getKey()));

        $this->postJson('/media/trash/restore', ['media' => [$media->uuid]])->assertOk();
        $this->assertNotNull(Media::query()->find($media->getKey()));
    }

    public function test_purging_takes_the_bytes_with_it(): void
    {
        $media = $this->media();
        $path = $media->path;

        $this->postJson('/media/trash/purge', ['media' => [$media->uuid]])->assertOk();

        $this->assertNull(Media::withTrashed()->find($media->getKey()));
        $this->assertFalse($this->app['filesystem']->disk('media')->exists($path));
    }

    public function test_emptying_the_trash(): void
    {
        $thrown = $this->media();
        $thrown->delete();
        $kept = $this->media();

        $this->deleteJson('/media/trash')->assertOk();

        $this->assertNull(Media::withTrashed()->find($thrown->getKey()));
        $this->assertNotNull(Media::query()->find($kept->getKey()));
    }

    public function test_an_empty_batch_is_refused(): void
    {
        /*
         * ⚠️ AN ACTION THAT "SUCCEEDS" WITHOUT DOING ANYTHING HIDES THE REAL PROBLEM, upstream —
         * a screen that lost its selection, a client sending the wrong field.
         */
        $this->postJson('/media/trash', [])->assertStatus(422);
    }

    public function test_a_batch_mixing_mine_and_a_foreign_one_executes_nothing(): void
    {
        $mine = $this->media();

        self::$current = 'org:b';
        $foreign = $this->media();
        self::$current = 'org:a';

        $this->postJson('/media/trash', ['media' => [$mine->uuid, $foreign->uuid]])
            ->assertNotFound();

        $this->assertNotNull(Media::query()->find($mine->getKey()));
    }

    // ── The access policy ────────────────────────────────────────────────────

    public function test_a_policy_that_refuses_blocks_uploading(): void
    {
        $this->forbidEverything();

        $this->post('/media', ['files' => [$this->image()]])->assertForbidden();

        $this->assertSame(0, Media::query()->count());
    }

    public function test_a_policy_that_refuses_blocks_renaming(): void
    {
        $media = $this->media();
        $this->forbidEverything();

        $this->patchJson('/media/'.$media->uuid, ['name' => 'Balance'])->assertForbidden();

        $this->assertSame('Report', $media->fresh()->name);
    }

    public function test_a_policy_that_refuses_blocks_the_trash_before_any_write(): void
    {
        $first = $this->media();
        $second = $this->media();
        $this->forbidEverything();

        $this->postJson('/media/trash', ['media' => [$first->uuid, $second->uuid]])
            ->assertForbidden();

        $this->assertNotNull(Media::query()->find($first->getKey()));
        $this->assertNotNull(Media::query()->find($second->getKey()));
    }

    public function test_a_folder_in_the_batch_is_authorised_too(): void
    {
        /*
         * ⚠️ ADDED AFTERWARDS. Every batch authorisation test acted on MEDIA only: removing the
         * check on FOLDERS entirely left the suite green. Yet it is the folder that carries the
         * most — its content and all its descendants.
         */
        $folder = $this->folder('Invoices');
        $this->forbidEverything();

        $this->postJson('/media/trash', ['folders' => [$folder->uuid]])->assertForbidden();

        $this->assertNotNull(MediaFolder::query()->find($folder->getKey()));
    }

    public function test_a_policy_that_refuses_blocks_copying(): void
    {
        /*
         * ⚠️ COPYING REQUIRES BOTH PERMISSIONS. Asking for only one lets either a reader create
         * files, or an uploader duplicate what they are not allowed to touch — and a copy is one
         * more instance, with the same value as the original.
         */
        $media = $this->media();
        $this->forbidEverything();

        $this->postJson('/media/'.$media->uuid.'/copy')->assertForbidden();

        $this->assertSame(1, Media::query()->count());
    }

    public function test_a_single_refused_item_brings_the_whole_batch_down(): void
    {
        /*
         * ⚠️ THE WHOLE-BATCH RULE, AT THE LEVEL OF RIGHTS THIS TIME. The module this package
         * replaces authorised nine items out of ten and executed anyway. A policy refusing ONE
         * of the two must leave BOTH intact.
         */
        $allowed = $this->media();
        $refused = $this->media();

        $forbidden = $refused->getKey();

        $this->app->singleton(AccessPolicy::class, fn () => new class($forbidden) implements AccessPolicy
        {
            public function __construct(private readonly mixed $forbidden)
            {
            }

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
                return true;
            }

            public function modify(Media|MediaFolder $item): bool
            {
                return true;
            }

            public function destroy(Media|MediaFolder $item): bool
            {
                return $item->getKey() !== $this->forbidden;
            }
        });

        $this->postJson('/media/trash', ['media' => [$allowed->uuid, $refused->uuid]])
            ->assertForbidden();

        $this->assertNotNull(Media::query()->find($allowed->getKey()));
        $this->assertNotNull(Media::query()->find($refused->getKey()));
    }

    public function test_reading_stays_possible_when_writing_is_refused(): void
    {
        /*
         * ⚠️ THE COUNTER-EXAMPLE. Without it, a broken policy refusing EVERYTHING would pass the
         * three tests above, and this file would certify a failure.
         */
        $media = $this->media();
        $this->forbidEverything();

        $this->getJson('/media/'.$media->uuid)->assertOk();
    }
}
