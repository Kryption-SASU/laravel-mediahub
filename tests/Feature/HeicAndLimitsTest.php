<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Enums\MediaType;
use Kryption\MediaHub\Exceptions\UploadRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\Conversions\ImagickConversionDriver;
use Kryption\MediaHub\Support\ImagickGuard;
use Kryption\MediaHub\Tests\Fixtures\SampleFiles;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * HEIC, AND THE BOUNDS THAT MAKE ACCEPTING IT TENABLE.
 *
 * ⚠️ HEIC IS THE DEFAULT FORMAT OF EVERY IPHONE PHOTO, and `getimagesize()` cannot open it. The
 * guard against decompression bombs therefore refused everything it could not measure — that is,
 * an entire mobile estate.
 *
 * ⚠️ OPENING IT WITHOUT BOUNDS WOULD BE WORSE THAN REFUSING IT. An image of a few kilobytes can
 * claim several gigabytes once expanded. This file therefore checks both halves of the decision:
 * HEIC gets in, and the decoder is held.
 *
 * ⚠️ AND THE MEASUREMENT PROVED INTUITION WRONG. ImageMagick's memory limits refuse nothing:
 * once exceeded, it spills to disk and carries on — a 4000×4000 image goes through under a
 * one-kilobyte limit. Only the dimension limits stop anything, and that is exactly what the last
 * test observes.
 */
class HeicAndLimitsTest extends TestCase
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

    private function file(string $bytes, string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return $path;
    }

    private function upload(string $bytes, string $name): Media
    {
        return $this->app->make(UploadMedia::class)(
            UploadedPayload::fromLocalFile($this->file($bytes, $name), $name)
        );
    }

    private function requireImagick(): void
    {
        if (! ImagickGuard::available()) {
            $this->markTestSkipped('Imagick absent: this test measures its bounds, not the package portability.');
        }
    }

    // ── HEIC gets in ─────────────────────────────────────────────────────────

    /**
     * ⚠️ THIS UPLOAD USED TO BE REFUSED — for a reason that had nothing to do with the file:
     * `getimagesize()` does not know HEIC, and refusal was the cautious default. Cautious against
     * what, on a host that cannot decode it either?
     */
    public function test_a_heic_photo_is_accepted_and_stored_as_it_is(): void
    {
        $bytes = SampleFiles::heic();

        $media = $this->upload($bytes, 'beach.heic');

        $this->assertSame('image/heic', $media->mime_type);
        $this->assertSame(MediaType::Image, $media->mediaType());
        $this->assertSame(
            $bytes,
            Storage::disk('media')->get((string) $media->path),
            'The photo must be served byte for byte.'
        );
    }

    /**
     * ⚠️ AND THE WIDENING STOPS THERE. A file declared as `image/png` whose header is unreadable
     * is still refused: `getimagesize()` can read PNGs, so its failure is a signal, not a gap.
     * Confusing the two would turn a narrow exception into an open door.
     */
    public function test_an_image_with_an_unreadable_header_is_still_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload("\x89PNG\r\n\x1a\n".str_repeat("\x00", 40), 'broken.png');
    }

    /** ⚠️ AND AN SVG NAMED `.heic` GETS THROUGH NO MORE THAN BEFORE. */
    public function test_an_executable_document_named_heic_is_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'photo.heic');
    }

    // ── Measuring without decoding ───────────────────────────────────────────

    /**
     * ⚠️ `pingImage()` READS THE HEADER ONLY, and that is the whole point: measuring an image to
     * find out whether it is too big by expanding it first would be doing exactly what the
     * measurement exists to prevent.
     */
    public function test_the_probe_measures_the_dimensions_without_expanding_the_image(): void
    {
        $this->requireImagick();

        $path = $this->file(SampleImages::bytes('image/png'), 'sample.png');

        $this->assertSame(24, ImagickGuard::pixels($path, ImagickGuard::limits($this->app['config'])));
    }

    /**
     * ⚠️ AND IT ANSWERS `null` RATHER THAN RAISING when nothing can open the file. It is that
     * answer which tells "I cannot measure" apart from "it is too big", and which allows the
     * upload on a host unable to decode — therefore unable to be caught out.
     */
    public function test_the_probe_returns_null_on_what_nobody_can_open(): void
    {
        $path = $this->file('this is not an image at all', 'mystery.bin');

        $this->assertNull(ImagickGuard::pixels($path, ImagickGuard::limits($this->app['config'])));
    }

    /**
     * ⚠️ ADVERTISED IS NOT AVAILABLE, AND THE PACKAGE NO LONGER CONFUSES THE TWO. On a host
     * without a working libheif, `queryFormats('HEIC')` answers "yes" all the same: neither
     * writing nor reading. PDF lies the same way when Ghostscript is missing, and `policy.xml`
     * makes it lie for a third reason again.
     *
     * ⚠️ THE TEST DOES NOT ASSERT THAT HEIC IS UNAVAILABLE — that would be false on a properly
     * equipped host. It demands AGREEMENT: what is promised is produced, what is not raises. A
     * driver taking `queryFormats()` at face value would promise a thumbnail here that it cannot
     * build, and would fall over.
     */
    public function test_a_format_advertised_by_the_delegate_is_only_promised_once_proven(): void
    {
        $this->requireImagick();

        Storage::disk('media')->put('beach.heic', SampleFiles::heic());

        $driver = new ImagickConversionDriver($this->app['filesystem'], $this->app['config']);
        $promises = $driver->supports('image/heic');

        try {
            $driver->convert('media', 'beach.heic', 'beach-thumb.png', ['width' => 4, 'height' => 4]);
            $succeeded = true;
        } catch (\Throwable) {
            $succeeded = false;
        }

        $this->assertSame($promises, $succeeded, sprintf(
            'HEIC: supports() answers "%s" and convert() %s.',
            $promises ? 'yes' : 'no',
            $succeeded ? 'succeeds' : 'fails'
        ));
    }

    // ── The bounds bite ──────────────────────────────────────────────────────

    /**
     * ⚠️ THE TEST THAT PREVENTS A DECORATIVE PROTECTION. Without it, one could set the
     * `setResourceLimit()` calls and believe the decoder held — while the memory limits would
     * have refused nothing at all. We tighten the dimension bound below the image's size, and
     * demand a refusal.
     *
     * ⚠️ CONFIGURATION ALONE IS ENOUGH TO DO IT, which proves at the same time that these bounds
     * are read on every operation and not frozen at boot.
     */
    public function test_imagemagick_bounds_are_actually_set(): void
    {
        $this->requireImagick();

        Storage::disk('media')->put('large.png', SampleImages::bytes('image/png'));

        config()->set('mediahub.images.limits.max_side', 2);

        $driver = new ImagickConversionDriver($this->app['filesystem'], $this->app['config']);

        $this->expectException(\RuntimeException::class);

        $driver->convert('media', 'large.png', 'large-thumb.png', ['width' => 1, 'height' => 1]);
    }

    /**
     * ⚠️ AND THE SAME IMAGE GOES THROUGH WITH THE SHIPPED BOUNDS. A protection that also refused
     * legitimate work would be turned off within the week, and we would have deserved it.
     */
    public function test_the_shipped_bounds_do_not_refuse_an_ordinary_image(): void
    {
        $this->requireImagick();

        Storage::disk('media')->put('photo.png', SampleImages::bytes('image/png'));

        $result = (new ImagickConversionDriver($this->app['filesystem'], $this->app['config']))
            ->convert('media', 'photo.png', 'photo-thumb.png', ['width' => 4, 'height' => 4]);

        $this->assertGreaterThan(0, $result['size']);
        Storage::disk('media')->assertExists('photo-thumb.png');
    }
}
