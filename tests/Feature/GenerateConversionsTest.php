<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\GenerateConversions;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Backends\LegacyConversionMirror;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Tests\Fixtures\SampleFiles;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * DERIVATIVES — and above all what has none.
 *
 * ⚠️ THE ORIGINAL IS NEVER TOUCHED, WHATEVER ITS TYPE. Not resized, not re-encoded, not
 * recompressed: the bytes uploaded are the bytes served. A media library that "optimises" what
 * it is entrusted with destroys without saying so, and nobody notices before they need the
 * original. Derivatives are EXTRA files.
 *
 * ⚠️ AND A FILE WITH NO PICTURE IN IT PRODUCES NO ROW — not even a "failed" one. A failed row
 * would display an error state for something that was never attempted, and would send someone
 * looking for a failure that does not exist. Nothing failed: there was nothing to do.
 *
 * ⚠️ WHICH IS NOT THE SAME STATEMENT AS "A VIDEO PRODUCES NO ROW", and it used to be. Since
 * ffmpeg was brought in, a real video gets a frame — see {@see ThumbnailsTest}. What the samples
 * here carry is a container and no stream: the right magic number and nothing behind it, which
 * is precisely what makes them the fixture for this rule and useless for the other one.
 */
class GenerateConversionsTest extends TestCase
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

    private function upload(string $bytes, string $name): Media
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return $this->app->make(UploadMedia::class)(UploadedPayload::fromLocalFile($path, $name));
    }

    /**
     * A GENUINELY DECODABLE PNG, and that is indispensable HERE.
     *
     * ⚠️ ELSEWHERE IN THE SUITE, A HAND-BUILT PNG HEADER IS ENOUGH: `finfo` and
     * `getimagesize()` only read the first bytes, and that avoids requiring GD in order to
     * exercise validation. But this file CONVERTS, therefore decodes — a header announcing
     * 40×30 without carrying the matching pixels produces a "failed" derivative, and the test
     * would accuse the package of a defect coming from its own sample.
     */
    private function png(): string
    {
        return SampleImages::bytes('image/png');
    }

    private function conversions(Media $media): int
    {
        return MediaConversion::query()->where('media_id', $media->getKey())->count();
    }

    // ── What does not convert ────────────────────────────────────────────────

    /**
     * ⚠️ VIDEO IS THE CASE THAT MOTIVATES THIS WHOLE FILE, and the full inventory of containers
     * lives in `VideoFormatsTest`. Here we keep the representative, and the document — which is
     * not in that inventory.
     */
    public function test_a_video_and_a_document_are_stored_as_they_are_with_no_derivative(): void
    {
        foreach ([['meeting.mp4', SampleFiles::mp4(), 'video/mp4'], ['notes.txt', SampleFiles::txt(), 'text/plain']] as [$name, $bytes, $type]) {
            $media = $this->upload($bytes, $name);

            $this->assertSame($type, $media->mime_type, $name);
            $this->assertSame($bytes, Storage::disk('media')->get((string) $media->path), $name.': bytes modified.');
            $this->assertSame(0, $this->conversions($media), $name.': derivative built by mistake.');
        }
    }

    /**
     * ⚠️ NOT EVEN A "FAILED" ROW. That is a decision, not a consequence: recording a failure for
     * a container with no stream in it would send someone looking for a fault where there was
     * nothing to attempt.
     *
     * ⚠️ AND THE `.wma` IS WHY IT STILL MATTERS with ffmpeg installed. ASF is one container for
     * both, so `finfo` answers `video/x-ms-asf` for a purely audio file: a video type with no
     * video in it, on every machine, for ever.
     */
    public function test_no_failed_row_is_left_behind_a_video(): void
    {
        $media = $this->upload(SampleFiles::mp4(), 'clip.mp4');

        $this->assertSame(
            0,
            MediaConversion::query()->where('state', ConversionState::Failed->value)->count(),
            'A "failed" derivative signals a fault; here there was nothing to do.'
        );
        $this->assertSame([], $this->app->make(GenerateConversions::class)($media));
    }

    /**
     * ⚠️ WITHOUT THIS TEST, ALL THE PRECEDING ONES WOULD BE EMPTY. A package that never built
     * anything would pass every one of them. So the same bench has to show that what must be
     * converted is — otherwise "no derivative" proves nothing.
     */
    public function test_but_an_image_does_produce_one(): void
    {
        if (! $this->app->make(ConversionDriver::class)->supports('image/png')) {
            $this->markTestSkipped('No image library here: the counterpart is checked where one is present.');
        }

        $media = $this->upload($this->png(), 'photo.png');

        $this->assertSame(1, $this->conversions($media), 'An image must produce its derivative.');

        $derivative = MediaConversion::query()->where('media_id', $media->getKey())->firstOrFail();

        $this->assertSame(ConversionState::Ready, $derivative->state);
        $this->assertGreaterThan(0, (int) $derivative->size);
        Storage::disk('media')->assertExists((string) $derivative->path);
    }

    /**
     * ⚠️ AND THE ORIGINAL STAYS INTACT EVEN WHEN A DERIVATIVE IS BUILT. That is the easiest case
     * to lose: the temptation to overwrite the source with its resized version is exactly what
     * makes the loss irreversible.
     */
    public function test_the_original_is_not_reencoded_even_when_a_derivative_exists(): void
    {
        $bytes = $this->png();

        $media = $this->upload($bytes, 'photo.png');

        $this->assertSame(
            hash('sha256', $bytes),
            hash('sha256', (string) Storage::disk('media')->get((string) $media->path)),
            'The original bytes have changed.'
        );
    }

    // ── The derivative's name describes its content ──────────────────────────

    /**
     * ⚠️ THE THUMBNAIL OF A PDF IS AN IMAGE, AND ITS FILE MUST SAY SO. `report-thumb.pdf` would
     * be served with the wrong type by every host that deduces one from the other — and there
     * are many. The same goes for TIFF and HEIC, which can be read but not looked at.
     */
    public function test_a_format_that_cannot_be_looked_at_comes_out_as_png(): void
    {
        $driver = $this->app->make(ConversionDriver::class);

        foreach (['application/pdf', 'image/tiff', 'image/heic'] as $type) {
            $this->assertSame('image/png', $driver->outputMimeType($type), $type.': unexpected output.');
        }
    }

    /**
     * ⚠️ THE DERIVATIVE KEEPS ITS SOURCE'S FORMAT WHEN THE MACHINE CAN WRITE IT. Pushing
     * everything to PNG would multiply the weight of photographs.
     *
     * ⚠️ AND THE TEST DOES NOT ASSERT THAT IT CAN: it demands AGREEMENT with what the driver
     * declares. On a GD built without libjpeg, the right answer is `image/png`.
     */
    public function test_the_derivative_keeps_its_source_format_when_possible(): void
    {
        $driver = $this->app->make(ConversionDriver::class);

        foreach (['image/jpeg', 'image/webp', 'image/gif'] as $type) {
            $this->assertSame(
                $driver->supports($type) ? $type : 'image/png',
                $driver->outputMimeType($type),
                $type.': the output format does not follow what the driver can do.'
            );
        }
    }

    // ── Outside the request ──────────────────────────────────────────────────

    /**
     * ⚠️ RESIZING IS COUNTED IN SECONDS. Doing it during the upload makes the person uploading
     * wait, and a multiple upload multiplies that wait until it times out — for an accessory
     * whose absence prevents nothing.
     */
    public function test_the_work_is_handed_to_the_queue(): void
    {
        Bus::fake();

        $media = $this->upload($this->png(), 'photo.png');

        Bus::assertDispatched(
            GenerateConversionsJob::class,
            static fn (GenerateConversionsJob $job): bool => $job->media->is($media)
        );
    }

    /**
     * ⚠️ AND THE VIDEO GOES THROUGH THE QUEUE TOO. Sorting before entering it would mean holding
     * the rule "what converts" TWICE — here and in the job — and they would diverge. It is the
     * job that interrogates the driver, and it alone.
     */
    public function test_a_video_goes_through_it_too_and_the_job_decides(): void
    {
        Bus::fake();

        $this->upload(SampleFiles::mp4(), 'clip.mp4');

        Bus::assertDispatched(GenerateConversionsJob::class);
    }

    /**
     * ⚠️ THE MIRROR IS A TRANSITIONAL MEASURE, AND IT SLEEPS HERE. It exists only for an adopted
     * schema whose old module still reads thumbnails from a JSON block. In standalone mode there
     * is no old module: writing the reflection anyway would leave a key in the free-form
     * properties that nothing reads and nobody would clean up.
     */
    public function test_in_standalone_mode_no_derivative_is_mirrored(): void
    {
        $mirror = $this->app->make(LegacyConversionMirror::class);

        $this->assertSame([], $mirror->mirrored());
        $this->assertFalse($mirror->reflects('thumb'));
    }
}
