<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\CopyMedia;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Actions\MoveMedia;
use Kryption\MediaHub\Actions\RenameMedia;
use Kryption\MediaHub\Actions\UpdateMediaMeta;
use Kryption\MediaHub\Contracts\QuotaPolicy;
use Kryption\MediaHub\Events\MediaCopied;
use Kryption\MediaHub\Events\MediaMoved;
use Kryption\MediaHub\Events\MediaRenamed;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Exceptions\QuotaExceeded;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Tests\TestCase;

/**
 * WHAT IS DONE TO A MEDIA WITHOUT DESTROYING IT — renaming, moving, copying, annotating.
 *
 * ⚠️ THREE OF THESE FOUR OPERATIONS MUST TOUCH NO BYTE, and that is the property this file
 * watches. A rename or a move that made the files follow would turn an instant, reversible
 * operation into a copy followed by a deletion, on remote storage, with no transaction to cover
 * them.
 */
class MediaActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    private function media(array $attributes = []): Media
    {
        $media = Media::create(array_merge([
            'disk' => 'media',
            'path' => '2026/08/report.pdf',
            'name' => 'Annual report',
            'file_name' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 2048,
        ], $attributes));

        Storage::disk('media')->put($media->path, 'some bytes');

        return $media;
    }

    // ── Renaming ─────────────────────────────────────────────────────────────

    public function test_renaming_only_changes_the_displayed_name(): void
    {
        $media = $this->media();

        $this->app->make(RenameMedia::class)($media, 'Balance 2026');

        $fresh = $media->fresh();

        $this->assertSame('Balance 2026', $fresh->name);
        $this->assertSame('report.pdf', $fresh->file_name);
        $this->assertSame('2026/08/report.pdf', $fresh->path);
        $this->assertSame('pdf', $fresh->extension);
        $this->assertSame('application/pdf', $fresh->mime_type);
    }

    public function test_renaming_does_not_move_the_bytes(): void
    {
        $media = $this->media();

        $this->app->make(RenameMedia::class)($media, 'Balance 2026');

        Storage::disk('media')->assertExists('2026/08/report.pdf');
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->expectException(OperationRejected::class);

        $this->app->make(RenameMedia::class)($this->media(), '  ');
    }

    public function test_renaming_to_pdf_does_not_make_the_media_a_pdf(): void
    {
        /*
         * ⚠️ THE TYPE IS READ FROM THE CONTENT, NOT FROM A TYPED STRING. A module that re-derives
         * the extension from the displayed name lets anyone rebrand an executable as an image —
         * and it is the name, not the content, that will decide how it is served.
         */
        $media = $this->media([
            'mime_type' => 'image/png',
            'extension' => 'png',
            'file_name' => 'logo.png',
            'path' => '2026/08/logo.png',
            'type' => 'image',
        ]);

        $this->app->make(RenameMedia::class)($media, 'invoice.pdf');

        $this->assertSame('image/png', $media->fresh()->mime_type);
        $this->assertSame('png', $media->fresh()->extension);
        $this->assertSame('logo.png', $media->fresh()->file_name);
    }

    // ── Moving ───────────────────────────────────────────────────────────────

    public function test_moving_changes_the_folder_and_nothing_else(): void
    {
        $media = $this->media();
        $folder = $this->app->make(CreateFolder::class)('Invoices');

        $this->app->make(MoveMedia::class)($media, $folder);

        $this->assertSame($folder->getKey(), $media->fresh()->folder_id);
        $this->assertSame('2026/08/report.pdf', $media->fresh()->path);
        Storage::disk('media')->assertExists('2026/08/report.pdf');
    }

    public function test_moving_to_the_root_detaches_from_the_folder(): void
    {
        $folder = $this->app->make(CreateFolder::class)('Invoices');
        $media = $this->media(['folder_id' => $folder->getKey()]);

        $this->app->make(MoveMedia::class)($media, null);

        $this->assertNull($media->fresh()->folder_id);
    }

    // ── Copying ──────────────────────────────────────────────────────────────

    public function test_copying_produces_a_second_row_and_a_second_object(): void
    {
        $media = $this->media();

        $copy = $this->app->make(CopyMedia::class)($media);

        $this->assertNotSame($media->getKey(), $copy->getKey());
        $this->assertNotSame($media->path, $copy->path);
        $this->assertNotSame($media->uuid, $copy->uuid);

        Storage::disk('media')->assertExists($media->path);
        Storage::disk('media')->assertExists($copy->path);
        $this->assertSame('some bytes', Storage::disk('media')->get($copy->path));
    }

    public function test_the_copy_stays_next_to_its_original(): void
    {
        $copy = $this->app->make(CopyMedia::class)($this->media());

        $this->assertStringStartsWith('2026/08/', $copy->path);
    }

    public function test_deduplication_does_not_short_circuit_a_copy(): void
    {
        /*
         * ⚠️ THE SAME CHECKSUM, AND YET TWO ROWS. On write, two uploads of the same content may
         * legitimately produce only one; a copy is an EXPLICIT request for a second instance.
         * Reusing would make the action silently inert — success on screen, nothing created.
         */
        $media = $this->media(['checksum' => str_repeat('a', 64)]);

        $copy = $this->app->make(CopyMedia::class)($media);

        $this->assertSame($media->checksum, $copy->checksum);
        $this->assertSame(2, Media::query()->where('checksum', $media->checksum)->count());
    }

    public function test_copying_into_another_folder_is_possible(): void
    {
        $target = $this->app->make(CreateFolder::class)('Archives');

        $copy = $this->app->make(CopyMedia::class)($this->media(), $target);

        $this->assertSame($target->getKey(), $copy->folder_id);
    }

    public function test_the_quota_is_checked_before_the_copy(): void
    {
        $this->app->singleton(QuotaPolicy::class, fn () => new class implements QuotaPolicy
        {
            public function limitInBytes(?string $scopeKey): ?int
            {
                return 0;
            }

            public function usedInBytes(?string $scopeKey): int
            {
                return 0;
            }

            public function allows(?string $scopeKey, int $incomingBytes): bool
            {
                return false;
            }
        });

        $media = $this->media();

        try {
            $this->app->make(CopyMedia::class)($media);
            $this->fail('the copy should have been refused');
        } catch (QuotaExceeded) {
            // expected
        }

        /* ⚠️ REFUSED BEFORE THE WRITE: no object was placed, no row exists. */
        $this->assertSame(1, Media::query()->count());
        $this->assertCount(1, Storage::disk('media')->allFiles());
    }

    // ── Annotating ───────────────────────────────────────────────────────────

    public function test_the_free_form_properties_merge(): void
    {
        $media = $this->media(['custom_properties' => ['alt' => 'A report', 'credit' => 'Studio']]);

        $this->app->make(UpdateMediaMeta::class)($media, ['alt' => 'The balance']);

        $this->assertSame(
            ['alt' => 'The balance', 'credit' => 'Studio'],
            $media->fresh()->custom_properties
        );
    }

    public function test_a_null_value_erases_the_property(): void
    {
        $media = $this->media(['custom_properties' => ['alt' => 'A report', 'credit' => 'Studio']]);

        $this->app->make(UpdateMediaMeta::class)($media, ['credit' => null]);

        $this->assertSame(['alt' => 'A report'], $media->fresh()->custom_properties);
    }

    public function test_annotating_a_media_without_properties_works(): void
    {
        $media = $this->media();

        $this->app->make(UpdateMediaMeta::class)($media, ['alt' => 'A report']);

        $this->assertSame(['alt' => 'A report'], $media->fresh()->custom_properties);
    }

    // ── Events ───────────────────────────────────────────────────────────────

    public function test_every_action_emits_its_event(): void
    {
        Event::fake([MediaRenamed::class, MediaMoved::class, MediaCopied::class]);

        $media = $this->media();

        $this->app->make(RenameMedia::class)($media, 'Balance');
        $this->app->make(MoveMedia::class)($media, null);
        $this->app->make(CopyMedia::class)($media);

        Event::assertDispatched(MediaRenamed::class, 1);
        Event::assertDispatched(MediaMoved::class, 1);
        Event::assertDispatched(MediaCopied::class, 1);
    }

    public function test_the_copy_event_carries_both_rows(): void
    {
        Event::fake([MediaCopied::class]);

        $media = $this->media();
        $copy = $this->app->make(CopyMedia::class)($media);

        Event::assertDispatched(
            MediaCopied::class,
            static fn (MediaCopied $e): bool => $e->source->is($media) && $e->copy->is($copy)
        );
    }
}
