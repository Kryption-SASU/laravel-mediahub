<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\MediaCollections;
use Kryption\MediaHub\Tests\Fixtures\HostModel;
use Kryption\MediaHub\Tests\Fixtures\SampleFiles;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\MediaCollection;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * ATTACHING MEDIA TO A HOST MODEL.
 *
 * ⚠️ THE PROPERTY THIS FILE DEFENDS IS THAT ONE RELATION REPLACES A COLUMN PER CASE. A product
 * without it grows `cover_media_id`, `avatar_media_id`, `document_media_id` and a pivot invented
 * on the spot — six of them were counted on the estate that motivated this package. None of them
 * can be listed together, and nothing says which files are still referenced.
 *
 * ⚠️ AND `addExistingMedia()` IS WHAT MAKES IT A MEDIA LIBRARY rather than an upload field.
 * Attaching a file the user already has must cost one row and no bytes.
 */
class HasMediaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        Storage::fake('archive');
        config()->set('mediahub.storage.disk', 'media');

        HostModel::createTable();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];

        HostModel::dropTable();

        parent::tearDown();
    }

    private function host(): HostModel
    {
        return HostModel::create(['title' => 'An article']);
    }

    private function payload(string $bytes, string $name): UploadedPayload
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return UploadedPayload::fromLocalFile($path, $name);
    }

    private function png(string $name = 'photo.png'): UploadedPayload
    {
        return $this->payload(SampleImages::bytes('image/png'), $name);
    }

    /** A media already in the library — what a picker hands back. */
    private function existing(string $path = 'library/report.pdf'): Media
    {
        $media = Media::create([
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

    // ── Declaring what is accepted ───────────────────────────────────────────

    /**
     * ⚠️ THE REGISTRAR IS READ WITHOUT UPLOADING ANYTHING, and that is the point of passing it
     * as an argument. A screen can ask what a model accepts before showing a file input.
     */
    public function test_a_model_declares_its_collections_and_they_can_be_read(): void
    {
        $collections = $this->host()->mediaCollections();

        $this->assertInstanceOf(MediaCollections::class, $collections);
        $this->assertSame(
            ['cover', 'attachments', 'brochure', 'tiny', 'avatar'],
            array_keys($collections->all())
        );

        $this->assertTrue($collections->get('cover')->isSingle());
        $this->assertFalse($collections->get('attachments')->isSingle());
        $this->assertSame(4096, $collections->get('cover')->maxSizeInKilobytes());
    }

    /**
     * ⚠️ AN UNDECLARED COLLECTION IS NOT AN ERROR. A package that refused to attach under a name
     * nobody registered would demand configuration before doing anything — and the name is not a
     * security boundary, the scope is.
     */
    public function test_an_undeclared_collection_is_unconstrained_rather_than_refused(): void
    {
        $host = $this->host();

        $rules = $host->mediaCollection('invented');

        $this->assertInstanceOf(MediaCollection::class, $rules);
        $this->assertFalse($rules->isSingle());
        $this->assertNull($rules->maxSizeInKilobytes());
        $this->assertTrue($rules->acceptsType('application/x-anything'));

        $host->addExistingMedia($this->existing(), 'invented');

        $this->assertCount(1, $host->getMedia('invented'));
    }

    /** ⚠️ REGISTERING THE SAME NAME TWICE MUST NOT SILENTLY DROP ONE OF THE TWO DECLARATIONS. */
    public function test_adding_a_collection_twice_returns_the_same_definition(): void
    {
        $collections = new MediaCollections();

        $first = $collections->add('cover')->single();
        $second = $collections->add('cover')->accepts('image/*');

        $this->assertSame($first, $second);
        $this->assertTrue($collections->get('cover')->isSingle());
        $this->assertTrue($collections->get('cover')->acceptsType('image/png'));
    }

    // ── Attaching what is already there ──────────────────────────────────────

    /**
     * ⚠️ NO BYTES ARE WRITTEN, AND THAT IS THE WHOLE ADVANTAGE. A library that could only attach
     * an upload would make the user send the same file again — a second copy on the storage, a
     * second row, and two things to delete later.
     */
    public function test_attaching_an_existing_media_writes_no_bytes_and_creates_no_row(): void
    {
        $host = $this->host();
        $media = $this->existing();

        $before = Storage::disk('media')->allFiles();

        $host->addExistingMedia($media, 'attachments');

        $this->assertSame($before, Storage::disk('media')->allFiles(), 'bytes were written');
        $this->assertSame(1, Media::query()->count(), 'a second media row was created');
        $this->assertTrue($host->getMedia('attachments')->first()->is($media));
    }

    /** ⚠️ A DOUBLE CLICK IS NOT AN ERROR. The pivot would refuse the row; refusing loudly would not help. */
    public function test_attaching_the_same_media_twice_changes_nothing(): void
    {
        $host = $this->host();
        $media = $this->existing();

        $host->addExistingMedia($media, 'attachments');
        $host->addExistingMedia($media, 'attachments');

        $this->assertCount(1, $host->getMedia('attachments'));
    }

    /** The same media may legitimately serve two purposes on the same model. */
    public function test_the_same_media_can_sit_in_two_collections(): void
    {
        $host = $this->host();
        $media = $this->existing();

        $host->addExistingMedia($media, 'attachments');
        $host->addExistingMedia($media, 'brochure');

        $this->assertCount(1, $host->getMedia('attachments'));
        $this->assertCount(1, $host->getMedia('brochure'));
    }

    /**
     * ⚠️ COLLECTIONS DO NOT LEAK INTO EACH OTHER. Without the pivot condition, `getMedia()`
     * returns everything attached to the model and a cover shows up among the attachments.
     */
    public function test_a_collection_only_returns_its_own(): void
    {
        $host = $this->host();

        $host->addExistingMedia($this->existing('library/a.pdf'), 'attachments');
        $host->addExistingMedia($this->existing('library/b.pdf'), 'brochure');

        $this->assertCount(1, $host->getMedia('attachments'));
        $this->assertCount(1, $host->getMedia('brochure'));
        $this->assertCount(0, $host->getMedia('cover'));
    }

    /** ⚠️ AND TWO MODELS DO NOT SEE EACH OTHER'S. */
    public function test_two_models_do_not_share_their_attachments(): void
    {
        $first = $this->host();
        $second = $this->host();

        $first->addExistingMedia($this->existing('library/a.pdf'), 'attachments');

        $this->assertCount(1, $first->getMedia('attachments'));
        $this->assertCount(0, $second->getMedia('attachments'));
    }

    // ── Uploading through a collection ───────────────────────────────────────

    public function test_uploading_through_a_collection_stores_and_attaches(): void
    {
        $host = $this->host();

        $media = $host->addMedia($this->png(), 'cover');

        $this->assertSame('image/png', $media->mime_type);
        Storage::disk('media')->assertExists((string) $media->path);
        $this->assertTrue($host->getFirstMedia('cover')->is($media));
    }

    /**
     * ⚠️ THE TYPE IS READ FROM THE CONTENT, NOT FROM THE NAME. `accepts('image/*')` satisfied by
     * a declared extension accepts an executable document renamed `.png` — which is exactly the
     * disguise the upload validator exists to catch.
     */
    public function test_a_collection_refuses_a_type_it_does_not_accept(): void
    {
        $host = $this->host();

        $this->expectException(OperationRejected::class);

        $host->addMedia($this->payload(SampleFiles::mp4(), 'clip.mp4'), 'cover');
    }

    /**
     * ⚠️ AND IT REFUSES BEFORE THE BYTES ARE WRITTEN. Checking afterwards would mean deleting
     * what was just stored, which is the ordering mistake the upload action is built to avoid.
     */
    public function test_a_refused_type_leaves_nothing_on_the_storage(): void
    {
        $host = $this->host();

        try {
            $host->addMedia($this->payload(SampleFiles::mp4(), 'clip.mp4'), 'cover');
        } catch (OperationRejected) {
            // expected
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame(0, Media::query()->count());
        $this->assertCount(0, $host->getMedia('cover'));
    }

    /**
     * ⚠️ TWO KILOBYTES AGAINST A ONE-KILOBYTE CEILING, and the sample matters. The PNG used
     * elsewhere in this file weighs about a hundred bytes: passed here it would be accepted, the
     * test would be green, and it would have measured nothing.
     */
    public function test_a_collection_refuses_what_is_too_large_for_it(): void
    {
        $host = $this->host();

        $this->expectException(OperationRejected::class);

        $host->addMedia($this->payload(str_repeat('x', 2048), 'notes.txt'), 'tiny');
    }

    /** ⚠️ AND THE SAME COLLECTION ACCEPTS WHAT FITS — otherwise the ceiling could be refusing everything. */
    public function test_the_same_collection_accepts_what_fits(): void
    {
        $host = $this->host();

        $host->addMedia($this->payload('a few bytes', 'notes.txt'), 'tiny');

        $this->assertCount(1, $host->getMedia('tiny'));
    }

    /**
     * ⚠️ THE COUNTER-EXAMPLE. Without it, a collection guard that refused EVERYTHING would pass
     * the two tests above, and this file would certify a broken rule.
     */
    public function test_the_same_file_is_accepted_where_the_rules_allow_it(): void
    {
        $host = $this->host();

        $media = $host->addMedia($this->png(), 'attachments');

        $this->assertCount(1, $host->getMedia('attachments'));
        Storage::disk('media')->assertExists((string) $media->path);
    }

    /**
     * ⚠️ `onDisk()` HAS TO REACH THE STORAGE, or it is a setting that reads as a promise and
     * does nothing. The default resolver honours it; a host with its own resolver decides.
     */
    public function test_a_collection_can_send_its_uploads_to_another_disk(): void
    {
        $host = $this->host();
        $host->mediaCollections()->add('elsewhere')->onDisk('archive');

        $media = $host->addMedia($this->png(), 'elsewhere');

        $this->assertSame('archive', $media->disk);
        Storage::disk('archive')->assertExists((string) $media->path);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    // ── One at a time ────────────────────────────────────────────────────────

    /**
     * ⚠️ A SECOND AVATAR REPLACES THE FIRST rather than being refused. Refusing would make every
     * "change the picture" screen remove the old one itself, and the one that forgets leaves a
     * model with two and no way to say which is shown.
     */
    public function test_a_single_collection_keeps_only_the_last(): void
    {
        $host = $this->host();

        $first = $this->existing('library/one.pdf');
        $second = $this->existing('library/two.pdf');

        $host->addExistingMedia($first, 'cover');
        $host->addExistingMedia($second, 'cover');

        $this->assertCount(1, $host->getMedia('cover'));
        $this->assertTrue($host->getFirstMedia('cover')->is($second));
    }

    /** ⚠️ AND REPLACING DETACHES, IT DOES NOT DELETE. The file belongs to the library, not to the model. */
    public function test_replacing_in_a_single_collection_does_not_delete_the_file(): void
    {
        $host = $this->host();

        $first = $this->existing('library/one.pdf');
        $host->addExistingMedia($first, 'cover');
        $host->addExistingMedia($this->existing('library/two.pdf'), 'cover');

        $this->assertNotNull(Media::query()->find($first->getKey()));
        Storage::disk('media')->assertExists('library/one.pdf');
    }

    // ── Order ────────────────────────────────────────────────────────────────

    /**
     * ⚠️ THE ORDER IS KEPT, AND IT IS NOT DECORATIVE. A gallery is arranged by a human dragging
     * thumbnails; returning the database's order throws that work away silently.
     */
    public function test_attachments_come_back_in_the_order_they_were_added(): void
    {
        $host = $this->host();

        $a = $this->existing('library/a.pdf');
        $b = $this->existing('library/b.pdf');
        $c = $this->existing('library/c.pdf');

        foreach ([$c, $a, $b] as $media) {
            $host->addExistingMedia($media, 'attachments');
        }

        $this->assertSame(
            [$c->getKey(), $a->getKey(), $b->getKey()],
            $host->getMedia('attachments')->map->getKey()->all()
        );
    }

    /**
     * ⚠️ A DETACHED POSITION IS NOT REUSED. Counting the rows to pick the next position gives
     * the same number twice as soon as something has been removed, and two media then share a
     * position — after which the order is whatever the engine feels like.
     */
    public function test_removing_one_does_not_make_two_share_a_position(): void
    {
        $host = $this->host();

        $a = $this->existing('library/a.pdf');
        $b = $this->existing('library/b.pdf');
        $c = $this->existing('library/c.pdf');

        $host->addExistingMedia($a, 'attachments');
        $host->addExistingMedia($b, 'attachments');
        $host->removeMedia($a, 'attachments');
        $host->addExistingMedia($c, 'attachments');

        $positions = $host->getMedia('attachments')->map(
            static fn (Media $media): int => (int) $media->pivot->position
        )->all();

        $this->assertSame($positions, array_unique($positions), 'two attachments share a position');
        $this->assertSame([$b->getKey(), $c->getKey()], $host->getMedia('attachments')->map->getKey()->all());
    }

    public function test_syncing_replaces_the_collection_in_the_order_given(): void
    {
        $host = $this->host();

        $a = $this->existing('library/a.pdf');
        $b = $this->existing('library/b.pdf');
        $c = $this->existing('library/c.pdf');

        $host->syncMedia([$a, $b], 'attachments');
        $host->syncMedia([$c, $a], 'attachments');

        $this->assertSame([$c->getKey(), $a->getKey()], $host->getMedia('attachments')->map->getKey()->all());
    }

    /**
     * ⚠️ SYNCING ONE COLLECTION LEAVES THE OTHERS ALONE. A plain `sync()` on the relation drops
     * everything attached under every other name: replacing a cover would take the attachments
     * with it, and nothing would say so.
     */
    public function test_syncing_one_collection_does_not_touch_another(): void
    {
        $host = $this->host();

        $host->addExistingMedia($this->existing('library/kept.pdf'), 'brochure');
        $host->syncMedia([$this->existing('library/a.pdf')], 'attachments');

        $this->assertCount(1, $host->getMedia('brochure'));
        $this->assertCount(1, $host->getMedia('attachments'));
    }

    public function test_syncing_with_nothing_empties_the_collection(): void
    {
        $host = $this->host();
        $host->addExistingMedia($this->existing(), 'attachments');

        $host->syncMedia([], 'attachments');

        $this->assertCount(0, $host->getMedia('attachments'));
    }

    // ── Removing ─────────────────────────────────────────────────────────────

    /** ⚠️ DETACHING IS NOT DELETING, and confusing the two destroys a file somebody else uses. */
    public function test_removing_an_attachment_leaves_the_media_in_the_library(): void
    {
        $host = $this->host();
        $media = $this->existing();

        $host->addExistingMedia($media, 'attachments');
        $host->removeMedia($media, 'attachments');

        $this->assertCount(0, $host->getMedia('attachments'));
        $this->assertNotNull(Media::query()->find($media->getKey()));
        Storage::disk('media')->assertExists((string) $media->path);
    }

    public function test_clearing_a_collection_leaves_the_others(): void
    {
        $host = $this->host();

        $host->addExistingMedia($this->existing('library/a.pdf'), 'attachments');
        $host->addExistingMedia($this->existing('library/b.pdf'), 'brochure');

        $host->clearMediaCollection('attachments');

        $this->assertCount(0, $host->getMedia('attachments'));
        $this->assertCount(1, $host->getMedia('brochure'));
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * ⚠️ TIME IS FROZEN BECAUSE BOTH SIDES STAMP AN EXPIRY. Each call writes `now + ttl`
     * into the query string, so two calls that straddle a second boundary produce two
     * different strings and the assertion fails for no reason at all. It did, on a run that
     * had passed minutes earlier on the very same commit.
     */
    public function test_the_first_media_url_is_the_signed_one(): void
    {
        Carbon::setTestNow(Carbon::now());

        $host = $this->host();
        $media = $this->existing();

        $host->addExistingMedia($media, 'attachments');

        $this->assertSame(
            app(\Kryption\MediaHub\Contracts\UrlGenerator::class)->url($media),
            $host->getFirstMediaUrl('attachments')
        );

        Carbon::setTestNow();
    }

    /**
     * ⚠️ A FALLBACK IS A URL, NOT A MEDIA. One that were a real media would be deletable — and
     * the day somebody deleted it, every empty avatar in the product would break at once.
     */
    public function test_an_empty_collection_falls_back_when_one_is_declared(): void
    {
        $host = $this->host();

        $this->assertSame('https://example.test/anonymous.png', $host->getFirstMediaUrl('avatar'));
        $this->assertNull($host->getFirstMediaUrl('attachments'));
    }

    public function test_the_fallback_gives_way_to_a_real_media(): void
    {
        $host = $this->host();
        $host->addExistingMedia($this->existing(), 'avatar');

        $this->assertNotSame('https://example.test/anonymous.png', $host->getFirstMediaUrl('avatar'));
    }

    public function test_has_media_answers_per_collection(): void
    {
        $host = $this->host();
        $host->addExistingMedia($this->existing(), 'attachments');

        $this->assertTrue($host->hasMedia('attachments'));
        $this->assertFalse($host->hasMedia('cover'));
    }
}
