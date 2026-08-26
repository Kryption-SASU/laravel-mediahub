<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\GenerateConversions;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Support\ExternalTools;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * A PICTURE FOR A VIDEO AND FOR A DOCUMENT — the two types that had only an icon.
 *
 * ⚠️ THE FIXTURES ARE REAL FILES, and that is the whole point of this bench. The suite already
 * had synthetic samples — a few bytes carrying the right magic number — and they are exactly
 * right for testing that a type is recognised and that nothing is drawn from an empty container.
 * They can say nothing about a frame, because there is no frame in them.
 *
 * ⚠️ AND THE BENCH SKIPS RATHER THAN LIES WHERE THE TOOL IS ABSENT. A machine without ffmpeg is
 * a supported machine — the package declines and the health report says why — so a red mark
 * there would report a missing program as a broken package.
 */
class ThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config()->set('mediahub.storage.disk', 'media');
    }

    /**
     * ⚠️ THE ROUTES ARE REGISTERED AT BOOT, so this cannot be done in `setUp`. Set there, the
     * default `['web', 'auth']` is already in force and every request answers 401 — which reads
     * as an authorisation bug in the code being tested rather than as a bench that spoke too
     * late.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/files/'.$name);
    }

    private function upload(string $bytes, string $name): Media
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return $this->app->make(UploadMedia::class)(UploadedPayload::fromLocalFile($path, $name));
    }

    /**
     * ⚠️ NAMED, NOT "THE FIRST ONE". There are two definitions now, and a bench reading whichever
     * row the database returned first would assert against the small one on some runs and the
     * large one on others — passing either way, and proving nothing about either.
     */
    private function thumbnailOf(Media $media, string $name = 'thumb'): ?MediaConversion
    {
        return MediaConversion::query()
            ->where('media_id', $media->getKey())
            ->where('name', $name)
            ->first();
    }

    private function needs(string $tool): void
    {
        if (! $this->app->make(ExternalTools::class)->has($tool)) {
            $this->markTestSkipped($tool.' is not installed here, and that is a supported state.');
        }
    }

    private function needsPdfRenderer(): void
    {
        if ($this->app->make(ExternalTools::class)->pdfRenderer() === null) {
            $this->markTestSkipped('No PDF renderer here, and that is a supported state.');
        }
    }

    // ── A video ──────────────────────────────────────────────────────────────

    public function test_a_video_gets_a_picture(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        $media = $this->upload($this->fixture('clip.mp4'), 'clip.mp4');
        $thumb = $this->thumbnailOf($media);

        $this->assertNotNull($thumb, 'No derivative was recorded for a real video.');
        $this->assertSame(ConversionState::Ready, $thumb->state);
        $this->assertSame('image/jpeg', $thumb->mime_type);

        /* ⚠️ THE FILE IS LOOKED AT, NOT ONLY THE ROW. A row marked ready beside nothing on the
         * disk is the failure this whole pipeline is written to avoid. */
        $bytes = Storage::disk('media')->get((string) $thumb->path);

        $this->assertNotEmpty($bytes);
        $this->assertIsArray(@getimagesizefromstring((string) $bytes));
    }

    /**
     * ⚠️ THE FRAME IS THE ONE THAT WAS ASKED FOR, and this is the only way to say so from
     * outside: two seconds of a moving picture are two different images. Without the setting
     * being honoured, both runs would return the same bytes and this bench would be the only
     * thing that noticed.
     */
    public function test_the_second_that_was_configured_is_the_one_captured(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        /*
         * ⚠️ ONE FILE, BUILT TWICE — NOT TWO UPLOADS OF THE SAME BYTES. The package reuses
         * duplicates, so a second upload of the same clip returns the FIRST media: the bench
         * then compared one thumbnail with itself and reported that the setting did nothing.
         * That is the fixture lying, not the code.
         */
        config()->set('mediahub.video.frame_at', 0);

        $media = $this->upload($this->fixture('clip.mp4'), 'clip.mp4');
        $first = $this->pictureBytes($media);

        config()->set('mediahub.video.frame_at', 1);

        $this->app->make(GenerateConversions::class)($media);
        $later = $this->pictureBytes($media);

        $this->assertNotSame($first, $later, 'Both captures returned the same image.');
    }

    /**
     * ⚠️ A CAPTURE PAST THE END PRODUCES NOTHING AT ALL, SILENTLY. ffmpeg seeks, finds no frame,
     * writes no file and exits without complaint — so a two-second clip asked for at ten seconds
     * would fail with no failure anywhere. The length is read first and the request brought
     * inside it, and this bench is what says so: the fixture is two seconds long.
     */
    public function test_a_second_past_the_end_still_produces_a_picture(): void
    {
        $this->needs(ExternalTools::FFMPEG);
        $this->needs(ExternalTools::FFPROBE);

        config()->set('mediahub.video.frame_at', 10);

        $thumb = $this->thumbnailOf($this->upload($this->fixture('clip.mp4'), 'late.mp4'));

        $this->assertNotNull($thumb, 'Nothing was drawn for a capture past the end.');
        $this->assertSame(ConversionState::Ready, $thumb->state);
    }

    /**
     * ⚠️ PULLING TWO GIGABYTES FOR A 256-PIXEL IMAGE IS ABSURD, and the ceiling is what says so.
     * The refusal is recorded rather than silent: a thumbnail missing because of a policy is
     * something whoever set the policy should be able to find.
     */
    public function test_a_source_beyond_the_ceiling_is_not_pulled_down(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        config()->set('mediahub.tools.max_source_bytes', 10);

        $thumb = $this->thumbnailOf($this->upload($this->fixture('clip.mp4'), 'huge.mp4'));

        $this->assertNotNull($thumb);
        $this->assertSame(ConversionState::Failed, $thumb->state);
        $this->assertStringContainsString('too_large', (string) $thumb->error);
    }

    // ── The large one, for a screen showing one file ─────────────────────────

    /**
     * ⚠️ A VIDEO AND A DOCUMENT HAVE NO VIEWABLE ORIGINAL, so a panel showing one on its own used
     * to blow the 256-pixel thumbnail up to fill itself — which reads as a bad picture rather than
     * as the wrong size being asked for. The large derivative exists for exactly those.
     */
    public function test_a_video_gets_a_large_one_as_well(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        $media = $this->upload($this->fixture('clip.mp4'), 'clip.mp4');
        $preview = $this->thumbnailOf($media, 'preview');

        $this->assertNotNull($preview, 'No large derivative was built for a video.');
        $this->assertSame(ConversionState::Ready, $preview->state);

        /* ⚠️ AND IT IS BIGGER, which is the whole reason it exists. */
        $this->assertGreaterThan(
            (int) $this->thumbnailOf($media)->width,
            (int) $preview->width,
        );
    }

    /**
     * ⚠️ AND AN IMAGE GETS ONLY THE SMALL ONE. It already has an original worth showing; a second
     * large derivative for every photograph in a library is double the conversion work and double
     * the storage, to serve a screen that would not ask for it.
     */
    public function test_a_photograph_gets_only_the_small_one(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        $this->assertNotNull($this->thumbnailOf($media));
        $this->assertNull($this->thumbnailOf($media, 'preview'));
    }

    /**
     * ⚠️ THE PAYLOAD CARRIES ONE ADDRESS PER ROLE, AND IT USED TO CARRY "THE FIRST READY ONE".
     * With a single definition that was the same thing; with two it is a draw — a grid asking for
     * a thumbnail could be handed the full-size preview, on some rows and not others, and every
     * tile would quietly weigh four times what it should.
     */
    public function test_each_address_is_the_derivative_it_says_it_is(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        $media = $this->upload($this->fixture('clip.mp4'), 'clip.mp4');

        $body = $this->getJson('/media/'.$media->uuid)->assertOk()->json('data');

        $this->assertNotNull($body['thumbnail_url']);
        $this->assertNotNull($body['preview_url']);
        $this->assertNotSame($body['thumbnail_url'], $body['preview_url']);

        /* ⚠️ AND EACH POINTS AT ITS OWN FILE, which is what tells the two apart on the wire. */
        $this->assertStringContainsString(
            (string) $this->thumbnailOf($media)->path,
            (string) $body['thumbnail_url'],
        );
        $this->assertStringContainsString(
            (string) $this->thumbnailOf($media, 'preview')->path,
            (string) $body['preview_url'],
        );
    }

    /**
     * ⚠️ A PUBLISHED CONFIGURATION IS A SNAPSHOT, AND IT DOES NOT GROW WITH THE PACKAGE.
     * `mergeConfigFrom` merges at the top level only: a host whose file carries its own
     * `conversions` block replaces ours entirely, so a key added later never reaches them.
     *
     * ⚠️ THIS IS NOT HYPOTHETICAL. Measured in a real application the day the role names were
     * added: its published file listed one definition and neither role, so every `thumbnail_url`
     * in the payload came back null — a library that had thumbnails on Monday showing type icons
     * on Tuesday, for a key nobody had touched.
     */
    public function test_a_configuration_that_predates_the_roles_still_gets_its_thumbnails(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        /* Exactly what a published file from before this feature leaves behind. */
        $this->app['config']->set('mediahub.conversions.thumbnail', null);
        $this->app['config']->set('mediahub.conversions.preview', null);

        $this->getJson('/media/'.$media->uuid)
            ->assertOk()
            ->assertJsonPath('data.thumbnail_url', fn ($url): bool => is_string($url) && $url !== '');
    }

    /**
     * ⚠️ AND THE TWO ROLES DO NOT FALL BACK TO THE SAME DEFINITION. Answering `thumb` for both
     * would leave every payload valid, every address resolvable and every picture present — and
     * the large one would be the small one, blown up to fill a dialog, which is the exact fault
     * the second definition was added to fix. It would look like nothing had been done.
     */
    public function test_a_configuration_that_predates_the_roles_still_gets_the_large_one(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        $media = $this->upload($this->fixture('clip.mp4'), 'clip.mp4');

        $this->app['config']->set('mediahub.conversions.thumbnail', null);
        $this->app['config']->set('mediahub.conversions.preview', null);

        $body = $this->getJson('/media/'.$media->uuid)->assertOk()->json('data');

        $this->assertNotSame($body['thumbnail_url'], $body['preview_url']);
        $this->assertStringContainsString(
            (string) $this->thumbnailOf($media, 'preview')->path,
            (string) $body['preview_url'],
        );
    }

    /** ⚠️ AND A PHOTOGRAPH ANSWERS NULL for the large one rather than repeating the small one. */
    public function test_a_photograph_has_no_large_address(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        $body = $this->getJson('/media/'.$media->uuid)->assertOk()->json('data');

        $this->assertNotNull($body['thumbnail_url']);
        $this->assertNull($body['preview_url']);
    }

    // ── A document ───────────────────────────────────────────────────────────

    public function test_a_pdf_gets_a_picture_of_its_first_page(): void
    {
        $this->needsPdfRenderer();

        $media = $this->upload($this->fixture('page.pdf'), 'report.pdf');
        $thumb = $this->thumbnailOf($media);

        $this->assertNotNull($thumb, 'No derivative was recorded for a real PDF.');
        $this->assertSame(ConversionState::Ready, $thumb->state);
        $this->assertSame('image/png', $thumb->mime_type);

        $size = @getimagesizefromstring((string) Storage::disk('media')->get((string) $thumb->path));

        $this->assertIsArray($size);

        /*
         * ⚠️ THE PAGE IS NEVER CROPPED, even though the definition asks for `cover`. A document
         * is recognised by its head — the letterhead, the title — and a square crop of a
         * portrait page removes exactly that. The fixture is 200×300, so a square answer would
         * mean somebody had cut it.
         */
        $this->assertNotSame($size[0], $size[1], 'The page was cropped to a square.');
    }

    /** ⚠️ AND THE DERIVATIVE IS NAMED FOR WHAT IT HOLDS, not for what it came from. */
    public function test_the_derivative_of_a_pdf_is_not_called_a_pdf(): void
    {
        $this->needsPdfRenderer();

        $thumb = $this->thumbnailOf($this->upload($this->fixture('page.pdf'), 'report.pdf'));

        $this->assertNotNull($thumb);
        $this->assertStringEndsWith('.png', (string) $thumb->path);
    }

    /**
     * ⚠️ A CONVERSION THAT LEAVES HALF A VIDEO IN THE TEMPORARY DIRECTORY IS A DISK THAT FILLS
     * UP OVER WEEKS, and the first sign of it is something entirely unrelated refusing to write.
     * The copy and the scratch file are both taken away, including when the work threw.
     */
    public function test_nothing_is_left_behind_in_the_temporary_directory(): void
    {
        $this->needs(ExternalTools::FFMPEG);

        $before = $this->scratchFiles();

        $this->upload($this->fixture('clip.mp4'), 'clip.mp4');

        /* And once more with a source it cannot read, so the failing path is swept too. */
        config()->set('mediahub.tools.max_source_bytes', 10);
        $this->upload($this->fixture('page.pdf'), 'report.pdf');

        $this->assertSame($before, $this->scratchFiles(), 'Files were left in the scratch directory.');
    }

    /** @return array<int, string> */
    private function scratchFiles(): array
    {
        $found = glob(sys_get_temp_dir().'/mediahub-src-*') ?: [];

        sort($found);

        return $found;
    }

    private function pictureBytes(Media $media): string
    {
        $thumb = $this->thumbnailOf($media);

        $this->assertNotNull($thumb);

        return (string) Storage::disk('media')->get((string) $thumb->path);
    }
}
