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

    private function thumbnailOf(Media $media): ?MediaConversion
    {
        return MediaConversion::query()->where('media_id', $media->getKey())->first();
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
