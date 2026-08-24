<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Actions\EmptyTrash;
use Kryption\MediaHub\Actions\ForceDeleteItems;
use Kryption\MediaHub\Actions\PruneTrash;
use Kryption\MediaHub\Actions\RestoreItems;
use Kryption\MediaHub\Actions\TrashItems;
use Kryption\MediaHub\Events\ItemsPurged;
use Kryption\MediaHub\Events\ItemsRestored;
use Kryption\MediaHub\Events\ItemsTrashed;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * THE LIFECYCLE — trash, restore, permanent deletion.
 *
 * ⚠️ THE PROPERTY THAT HOLDS ALL THE REST: THE BYTES OUTLIVE THE TRASH. A soft delete that
 * erased the files would make restoring a lie — it would return rows naming nothing, that is,
 * dead images everyone sees and nobody can repair.
 *
 * ⚠️ AND THE SYMMETRIC ONE: PERMANENT DELETION MUST REALLY TAKE THE BYTES, derivatives included,
 * and nothing more. The original module forgot the content of folders: 6,302 orphaned objects on
 * the estate that served as the field.
 */
class TrashLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    private function folder(string $name, ?MediaFolder $parent = null): MediaFolder
    {
        return $this->app->make(CreateFolder::class)($name, $parent);
    }

    private function media(?MediaFolder $folder = null, string $path = '2026/08/report.pdf'): Media
    {
        $media = Media::create([
            'folder_id' => $folder?->getKey(),
            'disk' => 'media',
            'path' => $path,
            'name' => 'Report',
            'file_name' => basename($path),
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 1024,
        ]);

        Storage::disk('media')->put($path, 'some bytes');

        return $media;
    }

    private function items(array $media = [], array $folders = []): ResolvedItems
    {
        return new ResolvedItems(new Collection($media), new Collection($folders));
    }

    // ── The trash ────────────────────────────────────────────────────────────

    public function test_the_bytes_outlive_the_trash(): void
    {
        $media = $this->media();

        $this->app->make(TrashItems::class)($this->items([$media]));

        $this->assertNull(Media::query()->find($media->getKey()));
        $this->assertNotNull(Media::withTrashed()->find($media->getKey()));
        Storage::disk('media')->assertExists('2026/08/report.pdf');
    }

    public function test_a_folder_carries_its_content_and_its_descendants(): void
    {
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);
        $inside = $this->media($root, 'a.pdf');
        $below = $this->media($child, 'b.pdf');

        $this->app->make(TrashItems::class)($this->items([], [$root]));

        $this->assertNull(MediaFolder::query()->find($child->getKey()));
        $this->assertNull(Media::query()->find($inside->getKey()));
        $this->assertNull(Media::query()->find($below->getKey()));
    }

    public function test_the_whole_batch_carries_the_same_deletion_instant(): void
    {
        $root = $this->folder('Root');
        $media = $this->media($root, 'a.pdf');

        $this->app->make(TrashItems::class)($this->items([], [$root]));

        $this->assertSame(
            (string) MediaFolder::withTrashed()->find($root->getKey())->deleted_at,
            (string) Media::withTrashed()->find($media->getKey())->deleted_at
        );
    }

    public function test_what_was_already_in_the_trash_keeps_its_instant(): void
    {
        $root = $this->folder('Root');
        $older = $this->media($root, 'a.pdf');

        Media::withTrashed()->whereKey($older->getKey())
            ->update(['deleted_at' => Carbon::now()->subDays(10)]);

        $before = (string) Media::withTrashed()->find($older->getKey())->deleted_at;

        $this->app->make(TrashItems::class)($this->items([], [$root]));

        $this->assertSame($before, (string) Media::withTrashed()->find($older->getKey())->deleted_at);
    }

    // ── Restoring ────────────────────────────────────────────────────────────

    public function test_restoring_a_folder_gives_back_what_its_deletion_took(): void
    {
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);
        $media = $this->media($child, 'a.pdf');

        $this->app->make(TrashItems::class)($this->items([], [$root]));
        $this->app->make(RestoreItems::class)(
            $this->items([], [MediaFolder::withTrashed()->find($root->getKey())])
        );

        $this->assertNotNull(MediaFolder::query()->find($root->getKey()));
        $this->assertNotNull(MediaFolder::query()->find($child->getKey()));
        $this->assertNotNull(Media::query()->find($media->getKey()));
    }

    public function test_restoring_a_folder_does_not_give_back_what_was_thrown_away_before(): void
    {
        /*
         * ⚠️ SOMEBODY MADE THAT DECISION. Undoing it in passing, "because the file was there",
         * resurrects what was deliberately thrown away — and nobody would notice before seeing
         * it back on screen.
         */
        $root = $this->folder('Root');
        $thrown = $this->media($root, 'a.pdf');
        $following = $this->media($root, 'b.pdf');

        $this->app->make(TrashItems::class)($this->items([$thrown]));
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $this->app->make(TrashItems::class)($this->items([], [$root]));
        Carbon::setTestNow();

        $this->app->make(RestoreItems::class)(
            $this->items([], [MediaFolder::withTrashed()->find($root->getKey())])
        );

        $this->assertNotNull(Media::query()->find($following->getKey()));
        $this->assertNull(Media::query()->find($thrown->getKey()));
    }

    public function test_restoring_a_file_brings_back_its_ancestor_folders(): void
    {
        /*
         * ⚠️ WITHOUT THAT, THE RESTORE CANNOT BE SEEN. The file is no longer in the trash, it
         * appears in no listing, and nothing on screen says where it is.
         */
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);
        $media = $this->media($child, 'a.pdf');

        $this->app->make(TrashItems::class)($this->items([], [$root]));
        $this->app->make(RestoreItems::class)(
            $this->items([Media::withTrashed()->find($media->getKey())])
        );

        $this->assertNotNull(Media::query()->find($media->getKey()));
        $this->assertNotNull(MediaFolder::query()->find($child->getKey()));
        $this->assertNotNull(MediaFolder::query()->find($root->getKey()));
    }

    // ── Permanent deletion ───────────────────────────────────────────────────

    public function test_deleting_for_good_takes_the_row_and_the_bytes(): void
    {
        $media = $this->media();

        $this->app->make(ForceDeleteItems::class)($this->items([$media]));

        $this->assertNull(Media::withTrashed()->find($media->getKey()));
        Storage::disk('media')->assertMissing('2026/08/report.pdf');
    }

    public function test_derivatives_go_with_their_original_files_included(): void
    {
        $media = $this->media();

        Storage::disk('media')->put('2026/08/report-thumb.png', 'thumbnail');
        MediaConversion::create([
            'media_id' => $media->getKey(),
            'name' => 'thumb',
            'disk' => 'media',
            'path' => '2026/08/report-thumb.png',
            'state' => 'ready',
            'size' => 9,
        ]);

        $this->app->make(ForceDeleteItems::class)($this->items([$media]));

        $this->assertSame(0, MediaConversion::query()->count());
        Storage::disk('media')->assertMissing('2026/08/report-thumb.png');
    }

    public function test_bytes_still_claimed_are_not_erased(): void
    {
        /*
         * ⚠️ TWO ROWS CAN POINT AT THE SAME OBJECT — deduplication, a data migration, a `table`
         * mode plugged onto a schema that allows it. Erasing without checking punctures somebody
         * else's image, and nothing reports it before it is displayed.
         */
        $first = $this->media();
        $second = $this->media(null, '2026/08/report.pdf');

        $this->app->make(ForceDeleteItems::class)($this->items([$first]));

        $this->assertNotNull(Media::query()->find($second->getKey()));
        Storage::disk('media')->assertExists('2026/08/report.pdf');
    }

    public function test_deleting_a_folder_takes_its_content_trash_included(): void
    {
        $root = $this->folder('Root');
        $child = $this->folder('Child', $root);
        $visible = $this->media($root, 'a.pdf');
        $hidden = $this->media($child, 'b.pdf');

        $this->app->make(TrashItems::class)($this->items([$hidden]));
        $this->app->make(ForceDeleteItems::class)($this->items([], [$root]));

        $this->assertNull(Media::withTrashed()->find($visible->getKey()));
        $this->assertNull(Media::withTrashed()->find($hidden->getKey()));
        $this->assertNull(MediaFolder::withTrashed()->find($child->getKey()));
        Storage::disk('media')->assertMissing('a.pdf');
        Storage::disk('media')->assertMissing('b.pdf');
    }

    // ── Emptying and sweeping ────────────────────────────────────────────────

    public function test_emptying_the_trash_does_not_touch_what_is_visible(): void
    {
        $kept = $this->media(null, 'a.pdf');
        $thrown = $this->media(null, 'b.pdf');

        $this->app->make(TrashItems::class)($this->items([$thrown]));
        $this->app->make(EmptyTrash::class)();

        $this->assertNotNull(Media::query()->find($kept->getKey()));
        $this->assertNull(Media::withTrashed()->find($thrown->getKey()));
        Storage::disk('media')->assertExists('a.pdf');
        Storage::disk('media')->assertMissing('b.pdf');
    }

    public function test_the_sweep_only_takes_what_has_been_sitting_there_long_enough(): void
    {
        $recent = $this->media(null, 'a.pdf');
        $older = $this->media(null, 'b.pdf');

        $this->app->make(TrashItems::class)($this->items([$recent, $older]));

        Media::withTrashed()->whereKey($older->getKey())
            ->update(['deleted_at' => Carbon::now()->subDays(40)]);

        $this->app->make(PruneTrash::class)(30);

        $this->assertNotNull(Media::withTrashed()->find($recent->getKey()));
        $this->assertNull(Media::withTrashed()->find($older->getKey()));
    }

    // ── Events ───────────────────────────────────────────────────────────────

    public function test_every_step_emits_its_event(): void
    {
        Event::fake([ItemsTrashed::class, ItemsRestored::class, ItemsPurged::class]);

        $media = $this->media();

        $this->app->make(TrashItems::class)($this->items([$media]));
        $this->app->make(RestoreItems::class)($this->items([Media::withTrashed()->find($media->getKey())]));
        $this->app->make(ForceDeleteItems::class)($this->items([$media]));

        Event::assertDispatched(ItemsTrashed::class, 1);
        Event::assertDispatched(ItemsRestored::class, 1);
        Event::assertDispatched(ItemsPurged::class, 1);
    }

    public function test_an_empty_batch_does_nothing_and_emits_nothing(): void
    {
        Event::fake([ItemsTrashed::class, ItemsPurged::class]);

        $this->app->make(TrashItems::class)(ResolvedItems::empty());
        $this->app->make(ForceDeleteItems::class)(ResolvedItems::empty());

        Event::assertNotDispatched(ItemsTrashed::class);
        Event::assertNotDispatched(ItemsPurged::class);
    }
}
