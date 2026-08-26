<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Actions\GenerateConversions;
use Kryption\MediaHub\Concerns\HasMedia;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\MediaCollections;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * DERIVATIVES DECIDED BY THE COLLECTION.
 *
 * ⚠️ A COVER AND AN ATTACHMENT DO NOT NEED THE SAME THING. One is displayed large and wants a
 * wide version; the other is downloaded and wants nothing at all. A single global list means
 * every PDF in a folder of invoices costs queue time and storage for a thumbnail no screen shows.
 */
class CollectionConversionsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-collection-conversions',
            'serve' => false,
            'throw' => false,
        ]);

        $app['config']->set('mediahub.storage.disk', 'media');
        $app['config']->set('mediahub.conversions.definitions', [
            'thumb' => ['width' => 256, 'height' => 256, 'fit' => 'cover'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });
    }

    private function subject(): Model
    {
        return CollectionConversionsPost::create();
    }

    /**
     * ⚠️ A REAL PNG, NOT A FILE NAMED ONE. The upload reads the type from the content: bytes
     * that spell nothing are refused before any of this is reached, and the test would fail for
     * a reason that has nothing to do with derivatives.
     */
    private function payload(): UploadedPayload
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mh');

        file_put_contents($path, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));

        return UploadedPayload::fromLocalFile($path, 'photo.png');
    }

    /**
     * ⚠️ SAYING NOTHING KEEPS EXACTLY WHAT HAPPENED BEFORE. Otherwise adding this feature would
     * change the behaviour of every installation that never asked for it — the kind of change
     * nobody reads a release note for.
     */
    public function test_a_collection_that_says_nothing_gets_the_configured_set(): void
    {
        Bus::fake();

        $this->subject()->addMedia($this->payload(), 'attachments');

        Bus::assertDispatched(
            GenerateConversionsJob::class,
            static fn (GenerateConversionsJob $job): bool => $job->definitions === null,
        );
    }

    public function test_a_collection_can_ask_for_its_own(): void
    {
        Bus::fake();

        $this->subject()->addMedia($this->payload(), 'cover');

        Bus::assertDispatched(
            GenerateConversionsJob::class,
            static fn (GenerateConversionsJob $job): bool => array_keys($job->definitions ?? []) === ['hero'],
        );
    }

    /**
     * ⚠️ REPLACING, NOT MERGING. Merging would make the global `thumb` impossible to remove: a
     * collection that wants one large image would get two, with no way to say otherwise.
     */
    public function test_its_own_set_replaces_the_configured_one(): void
    {
        Bus::fake();

        $this->subject()->addMedia($this->payload(), 'cover');

        Bus::assertDispatched(
            GenerateConversionsJob::class,
            static fn (GenerateConversionsJob $job): bool => ! array_key_exists('thumb', $job->definitions ?? []),
        );
    }

    /** ⚠️ AND NONE AT ALL IS A THING A COLLECTION IS ALLOWED TO WANT. */
    public function test_a_collection_can_refuse_derivatives_entirely(): void
    {
        Bus::fake();

        $this->subject()->addMedia($this->payload(), 'documents');

        Bus::assertDispatched(
            GenerateConversionsJob::class,
            static fn (GenerateConversionsJob $job): bool => $job->definitions === [],
        );
    }

    /**
     * ⚠️ A FILE CHOSEN FROM THE LIBRARY GETS THEM TOO. Without this, the one case the library
     * exists for — picking something already there — would be the only one that never receives
     * the large version, and the screen would fall back to a thumbnail with nothing explaining
     * why.
     */
    public function test_attaching_an_existing_media_builds_what_the_collection_wants(): void
    {
        Bus::fake();

        $media = Media::create([
            'disk' => 'media',
            'path' => '2026/08/existing.png',
            'name' => 'Existing',
            'file_name' => 'existing.png',
            'extension' => 'png',
            'mime_type' => 'image/png',
            'type' => 'image',
            'size' => 10,
            'checksum' => str_repeat('a', 64),
        ]);

        $this->subject()->addExistingMedia($media, 'cover');

        Bus::assertDispatched(
            GenerateConversionsJob::class,
            static fn (GenerateConversionsJob $job): bool => array_keys($job->definitions ?? []) === ['hero'],
        );
    }

    /** ⚠️ AND NOTHING IS DISPATCHED WHERE THE COLLECTION WANTS NOTHING — a queue job to build zero files. */
    public function test_attaching_dispatches_nothing_where_nothing_is_wanted(): void
    {
        Bus::fake();

        $media = Media::create([
            'disk' => 'media',
            'path' => '2026/08/invoice.pdf',
            'name' => 'Invoice',
            'file_name' => 'invoice.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 10,
            'checksum' => str_repeat('b', 64),
        ]);

        $this->subject()->addExistingMedia($media, 'documents');

        Bus::assertNotDispatched(GenerateConversionsJob::class);
    }

    /**
     * WHAT IS ACTUALLY BUILT, NOT WHAT WAS ASKED FOR.
     *
     * ⚠️ THE TEST ABOVE WATCHES THE JOB; THIS ONE WATCHES THE WORK. Replacing rather than merging
     * is decided inside the action, and a test that only inspects the dispatched job stays green
     * while somebody changes it there — which is exactly what happened when this was checked by
     * mutation. Written after that, and for that reason.
     *
     * ⚠️ AND THE DRIVER IS A FAKE, so the test runs on a machine with no image library at all.
     * What is under test is which definitions are chosen, not how a picture is resized.
     */
    public function test_only_what_was_asked_for_is_built(): void
    {
        $this->app->singleton(ConversionDriver::class, static fn (): ConversionDriver => new class implements ConversionDriver
        {
            public function supports(string $mimeType): bool
            {
                return true;
            }

            public function needsAProgram(): bool
            {
                return false;
            }

            public function outputMimeType(string $sourceMimeType): string
            {
                return 'image/webp';
            }

            /** @return array{width: int|null, height: int|null, size: int} */
            public function convert(string $disk, string $path, string $target, array $definition): array
            {
                return ['width' => 1, 'height' => 1, 'size' => 1];
            }
        });

        $media = Media::create([
            'disk' => 'media',
            'path' => '2026/08/cover.png',
            'name' => 'Cover',
            'file_name' => 'cover.png',
            'extension' => 'png',
            'mime_type' => 'image/png',
            'type' => 'image',
            'size' => 10,
            'checksum' => str_repeat('c', 64),
        ]);

        $produced = $this->app->make(GenerateConversions::class)(
            $media,
            ['hero' => ['width' => 1200, 'fit' => 'contain']],
        );

        $names = array_map(static fn ($conversion): string => (string) $conversion->name, $produced);

        self::assertSame(['hero'], $names);
    }
}

class CollectionConversionsPost extends Model
{
    use HasMedia;

    protected $table = 'posts';

    protected $guarded = [];

    public function registerMediaCollections(MediaCollections $collections): void
    {
        $collections->add('cover')->conversions(['hero' => ['width' => 1200, 'fit' => 'contain']]);
        $collections->add('documents')->withoutConversions();
        $collections->add('attachments');
    }
}
