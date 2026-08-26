<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Support\Conversions\GdCapabilities;
use Kryption\MediaHub\Support\Conversions\GdConversionDriver;
use Kryption\MediaHub\Support\Conversions\ImagickConversionDriver;
use Kryption\MediaHub\Support\Conversions\NullConversionDriver;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE IMAGE DRIVERS — and above all their right to answer no.
 *
 * ⚠️ THE CENTRAL PROPERTY OF THIS FILE IS NEGATIVE: the package must be useful on a host WITHOUT
 * an image library. An intranet that only stores documents, a minimal runtime image: requiring
 * GD would shut the door on them when they have no use for thumbnails.
 *
 * ⚠️ NO TEST HERE ASSERTS THAT A FORMAT IS AVAILABLE. Such a test would be red on the next
 * host and would end up being weakened. What they assert is that the driver's answer and its
 * behaviour AGREE — a property that holds on any build, whatever it can actually do. The
 * pipeline runs the suite with GD alone, with Imagick alone, with both, and with neither.
 *
 * ⚠️ AND THE LIST THAT IS NOT THERE IS PROVEN, not assumed. A hardcoded set of formats
 * matching the machine it runs on would satisfy every test that only observes the real build.
 * `GdCapabilities` is injected, so a stripped GD is described in one line and the answer is
 * confronted with it — on any machine, including one with no GD at all.
 */
class ConversionDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    /**
     * ⚠️ WITHOUT GD WE SKIP — WE DO NOT FAIL. This file checks two things of different natures:
     * that the package CAN DO WITHOUT an image library, and that the GD driver builds correctly
     * when one is there. The first must stay verifiable on a host without GD — otherwise the
     * suite would demand what the package does not, and would lie about its own portability.
     */
    private function requireGd(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD absent: this test builds an image, it does not measure portability.');
        }
    }

    private function requireImagick(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick absent: this test measures that driver, not portability.');
        }
    }

    private function png(int $width = 60, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 30, 30));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    // ── The choice made by configuration ─────────────────────────────────────

    public function test_the_default_driver_is_gd(): void
    {
        $this->assertInstanceOf(GdConversionDriver::class, $this->app->make(ConversionDriver::class));
    }

    /**
     * ⚠️ IT IS THE SERVICE PROVIDER THAT CHOOSES, AND IT IS THE PROVIDER WE MEASURE.
     *
     * The first version of these two tests instantiated the driver itself, then checked that it
     * had indeed instantiated what it had just instantiated. Green, and empty: it tested the
     * test. So we set the configuration again, forget the resolved instance, and ask the
     * container once more.
     */
    private function resolvedDriver(string $configured): ConversionDriver
    {
        config()->set('mediahub.images.driver', $configured);

        $this->app->forgetInstance(ConversionDriver::class);

        return $this->app->make(ConversionDriver::class);
    }

    public function test_the_configuration_chooses_the_driver(): void
    {
        $this->assertInstanceOf(ImagickConversionDriver::class, $this->resolvedDriver('imagick'));
        $this->assertInstanceOf(GdConversionDriver::class, $this->resolvedDriver('gd'));
    }

    /**
     * ⚠️ A TYPO IN A CONFIGURATION DOES NOT BRING THE APPLICATION DOWN. It costs thumbnails,
     * which shows; it does not stop files that are actually there from being served.
     */
    public function test_an_unknown_driver_falls_back_to_the_one_that_can_do_nothing(): void
    {
        $this->assertInstanceOf(NullConversionDriver::class, $this->resolvedDriver('nonexistent'));
    }

    /**
     * ⚠️ WE DO NOT SWITCH TO ANOTHER DRIVER ON OUR OWN. Where Imagick is absent, the
     * provider still returns the REQUESTED driver, which will answer "I cannot". A silent
     * fallback would produce different thumbnails depending on the machine, and nobody would
     * know why staging and production do not render the same image.
     */
    public function test_the_requested_driver_is_returned_even_when_its_extension_is_missing(): void
    {
        $driver = $this->resolvedDriver('imagick');

        /* ⚠️ IT IS THE DRIVER'S IDENTITY THAT IS AT STAKE HERE, and it depends on no image. */
        $this->assertInstanceOf(ImagickConversionDriver::class, $driver);

        if (! extension_loaded('imagick')) {
            $this->assertFalse($driver->supports('image/jpeg'));
        }
    }

    // ── The right to answer no ───────────────────────────────────────────────

    /** ⚠️ IMAGICK IS NOT INSTALLED HERE: the driver must say so, not raise. */
    public function test_a_driver_without_its_extension_declares_that_it_cannot(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is present here: this half of the contract is checked on a host without it.');
        }

        $this->assertFalse((new ImagickConversionDriver($this->app['filesystem'], $this->app['config']))->supports('image/jpeg'));
    }

    /**
     * ⚠️ TWO PROPERTIES OF DIFFERENT NATURES, THEREFORE TWO TESTS. "GD does not read a PDF" is
     * true everywhere; "GD reads a PNG" presupposes GD. Mixing them made the suite fail on a
     * host without GD — that is, precisely the host this file claims to prove is served
     * correctly.
     */
    public function test_gd_does_not_claim_to_read_a_pdf(): void
    {
        $this->assertFalse((new GdConversionDriver($this->app['filesystem']))->supports('application/pdf'));
    }

    /**
     * ⚠️ PNG ONLY, AND THAT IS DELIBERATE. The first version asserted JPEG too: that is false on
     * a GD built without libjpeg, and such builds exist. PNG, on the other hand, comes with GD —
     * and the driver needs it in any case to WRITE its derivatives, so its absence would make
     * the driver entirely mute.
     */
    public function test_gd_reads_png_as_soon_as_it_is_there(): void
    {
        $this->requireGd();

        $this->assertTrue((new GdConversionDriver($this->app['filesystem']))->supports('image/png'));
    }

    /** ⚠️ AND WITHOUT GD, THE GD DRIVER ITSELF ANSWERS NO — without raising, without guessing. */
    public function test_without_gd_the_gd_driver_declares_that_it_cannot(): void
    {
        if (extension_loaded('gd')) {
            $this->markTestSkipped('GD is present: this half of the contract is checked elsewhere.');
        }

        $this->assertFalse((new GdConversionDriver($this->app['filesystem']))->supports('image/png'));
    }

    public function test_the_driver_that_can_do_nothing_never_pretends_otherwise(): void
    {
        $driver = new NullConversionDriver();

        $this->assertFalse($driver->supports('image/png'));
    }

    /**
     * ⚠️ ANSWERING NO AND THEN PRODUCING AN EMPTY RESULT WOULD BE WORSE THAN ANYTHING: the
     * caller would believe it had a thumbnail. Called anyway, the driver raises.
     */
    public function test_the_driver_that_can_do_nothing_raises_if_called_anyway(): void
    {
        $this->expectException(\LogicException::class);

        (new NullConversionDriver())->convert('media', 'a.png', 'b.png', []);
    }

    // ── What is promised must be kept ────────────────────────────────────────

    /**
     * ⚠️ `supports()` IS A PROMISE, AND IT IS THE PROMISE WE MEASURE. Answering yes and then
     * failing puts a thumbnail that will never exist into a listing; answering no when it could
     * have been done deprives without reason. So for every format we confront the answer with
     * the actual behaviour — on real bytes.
     *
     * ⚠️ AND WE COUNT THE ACCEPTED FORMATS. Without that last check, a driver refusing
     * EVERYTHING would sail through this test: the agreement would be perfect, and empty.
     */
    private function exerciseThePromise(ConversionDriver $driver, string $label): void
    {
        $kept = 0;

        foreach (array_keys(SampleImages::BY_TYPE) as $type) {
            $source = 'src/'.md5($type);
            $target = $source.'-thumb.png';

            Storage::disk('media')->put($source, SampleImages::bytes($type));

            $promises = $driver->supports($type);

            try {
                $driver->convert('media', $source, $target, ['width' => 4, 'height' => 4]);
                $succeeded = true;
            } catch (\Throwable) {
                $succeeded = false;
            }

            $this->assertSame($promises, $succeeded, sprintf(
                '%s / %s: supports() answers "%s" and convert() %s.',
                $label,
                $type,
                $promises ? 'yes' : 'no',
                $succeeded ? 'succeeds' : 'fails'
            ));

            if ($promises) {
                Storage::disk('media')->assertExists($target);
                $this->assertGreaterThan(
                    0,
                    (int) Storage::disk('media')->size($target),
                    $label.' / '.$type.': an empty derivative is worse than a refusal.'
                );
                $kept++;
            }
        }

        $this->assertGreaterThan(0, $kept, $label.': no format accepted, the agreement proves nothing.');
    }

    public function test_gd_keeps_what_it_promises_format_by_format(): void
    {
        $this->requireGd();

        $this->exerciseThePromise(new GdConversionDriver($this->app['filesystem']), 'gd');
    }

    public function test_imagick_keeps_what_it_promises_format_by_format(): void
    {
        $this->requireImagick();

        $this->exerciseThePromise(new ImagickConversionDriver($this->app['filesystem'], $this->app['config']), 'imagick');
    }

    /**
     * ⚠️ THIS ONE MEASURES THE WIRING TO THE REAL RUNTIME. The tests below describe a build by
     * hand and prove the driver follows what it is told; this one proves that what it is told,
     * by default, is what the machine actually reports.
     *
     * ⚠️ AND THE CHECK GOES THROUGH A DIFFERENT DOOR FROM THE CODE BEING MEASURED:
     * `imagetypes()`, where the driver interrogates `gd_info()`. Reusing the same source would
     * make a mirror, and a mirror never says no.
     */
    public function test_gd_follows_its_compile_options(): void
    {
        $this->requireGd();

        $driver = new GdConversionDriver($this->app['filesystem']);

        foreach (['image/jpeg' => IMG_JPG, 'image/png' => IMG_PNG, 'image/gif' => IMG_GIF, 'image/webp' => IMG_WEBP] as $type => $bit) {
            $this->assertSame(
                (bool) (imagetypes() & $bit),
                $driver->supports($type),
                $type.': the answer does not follow what GD was built with.'
            );
        }
    }

    // ── The list that is not there ───────────────────────────────────────────

    /**
     * ⚠️ THIS IS THE TEST A STRIPPED BUILD USED TO BE NEEDED FOR. A hardcoded set of formats
     * matching the machine it runs on satisfies every other test in this file: on a full GD,
     * "supports JPEG" is true whether the driver asked `gd_info()` or simply said yes. Catching
     * it needs a GD lacking a format the set would claim — and describing one is now a line of
     * code rather than a build of PHP.
     *
     * ⚠️ AND IT RUNS EVERYWHERE, including on a host with no GD at all: nothing here touches the
     * extension.
     */
    public function test_a_stripped_gd_refuses_the_formats_it_cannot_read(): void
    {
        $stripped = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true, 'GIF Read Support' => true])
        );

        $this->assertTrue($stripped->supports('image/png'), 'PNG is declared and must be accepted.');
        $this->assertTrue($stripped->supports('image/gif'), 'GIF reading is declared and must be accepted.');

        $this->assertFalse($stripped->supports('image/jpeg'), 'JPEG is not declared: something other than the build is answering.');
        $this->assertFalse($stripped->supports('image/webp'), 'WebP is not declared: something other than the build is answering.');
        $this->assertFalse($stripped->supports('image/avif'));
    }

    /**
     * ⚠️ WITHOUT PNG THE DRIVER IS MUTE, whatever else the build can read. Derivatives are
     * written as PNG, so a build unable to write one has nothing to offer — and saying otherwise
     * would put thumbnails that never arrive into a listing.
     */
    public function test_a_gd_that_cannot_write_png_supports_nothing(): void
    {
        $driver = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['JPEG Support' => true, 'WebP Support' => true])
        );

        $this->assertFalse($driver->supports('image/jpeg'));
        $this->assertFalse($driver->supports('image/webp'));
    }

    /** ⚠️ AND A MACHINE THAT DECLARES NOTHING REFUSES EVERYTHING, without raising. */
    public function test_a_gd_that_declares_nothing_refuses_everything(): void
    {
        $driver = new GdConversionDriver($this->app['filesystem'], GdCapabilities::absent());

        foreach (['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as $type) {
            $this->assertFalse($driver->supports($type), $type);
        }
    }

    /**
     * ⚠️ AN UNKNOWN FLAG IS A NO, AND THAT IS NOT A DETAIL. `gd_info()` omits what a build cannot
     * do rather than reporting it false; reading an absent key as anything else would promise
     * every format the driver's table happens to name.
     */
    public function test_a_capability_absent_from_the_report_is_a_refusal(): void
    {
        $driver = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true])
        );

        $this->assertFalse($driver->supports('image/bmp'));
    }

    /**
     * ⚠️ `gd_info()` MIXES BOOLEANS AND STRINGS — `GD Version` is a version number. A value that
     * is merely truthy must not be read as a capability, or every build would claim everything
     * its report happens to mention.
     */
    public function test_a_truthy_value_that_is_not_true_is_not_a_capability(): void
    {
        $driver = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true, 'JPEG Support' => '2.3.3'])
        );

        $this->assertFalse($driver->supports('image/jpeg'));
    }

    /**
     * ⚠️ READING A GIF IS NOT WRITING ONE, and GD says so with two separate flags. A build that
     * reads GIF without being able to create one must still produce a derivative — as a PNG.
     * Collapsing the two flags would write a file the machine cannot make.
     */
    public function test_reading_a_gif_does_not_mean_writing_one(): void
    {
        $reader = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true, 'GIF Read Support' => true])
        );

        $this->assertTrue($reader->supports('image/gif'));
        $this->assertSame('image/png', $reader->outputMimeType('image/gif'));

        $writer = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true, 'GIF Read Support' => true, 'GIF Create Support' => true])
        );

        $this->assertSame('image/gif', $writer->outputMimeType('image/gif'));
    }

    /**
     * ⚠️ TWO ANSWERS THAT DIFFER, FROM THE SAME CODE. One assertion alone would be satisfied by a
     * method that always returns `image/png`; it is the pair that shows the output follows what
     * the build declares.
     */
    public function test_the_output_format_follows_the_declared_capabilities(): void
    {
        $withJpeg = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true, 'JPEG Support' => true])
        );

        $withoutJpeg = new GdConversionDriver(
            $this->app['filesystem'],
            GdCapabilities::of(['PNG Support' => true])
        );

        $this->assertSame('image/jpeg', $withJpeg->outputMimeType('image/jpeg'));
        $this->assertSame('image/png', $withoutJpeg->outputMimeType('image/jpeg'));
    }

    /**
     * ⚠️ "THE FORMAT IS KNOWN" IS NOT "THE FORMAT IS READABLE". `queryFormats('PDF')` answers
     * yes on a host without Ghostscript, where reading fails; a distribution forbidding PDF in
     * `policy.xml` produces the same gap, for another reason. The driver must decide on what it
     * can actually do.
     */
    public function test_imagick_only_promises_pdf_when_it_can_really_read_it(): void
    {
        $this->requireImagick();

        $driver = new ImagickConversionDriver($this->app['filesystem'], $this->app['config']);

        Storage::disk('media')->put('doc.pdf', SampleImages::bytes('application/pdf'));

        if ($driver->supports('application/pdf')) {
            $this->assertNotSame([], (new \Imagick())->queryFormats('PDF'), 'Promising a format the delegate ignores.');

            $result = $driver->convert('media', 'doc.pdf', 'doc-thumb.png', ['width' => 4, 'height' => 4]);

            $this->assertGreaterThan(0, $result['size']);

            return;
        }

        $this->expectException(\RuntimeException::class);

        $driver->convert('media', 'doc.pdf', 'doc-thumb.png', ['width' => 4, 'height' => 4]);
    }

    // ── Building, for real ───────────────────────────────────────────────────

    public function test_gd_builds_a_thumbnail_that_fills_the_frame(): void
    {
        $this->requireGd();

        Storage::disk('media')->put('invoices/photo.png', $this->png(60, 40));

        $result = (new GdConversionDriver($this->app['filesystem']))->convert(
            'media',
            'invoices/photo.png',
            'invoices/photo-thumb.png',
            ['width' => 20, 'height' => 20, 'fit' => 'cover']
        );

        Storage::disk('media')->assertExists('invoices/photo-thumb.png');

        $this->assertSame(20, $result['width']);
        $this->assertSame(20, $result['height']);
        $this->assertSame('image/png', $result['mime_type']);
        $this->assertGreaterThan(0, $result['size']);
    }

    /** ⚠️ `contain` FITS INSIDE THE BOX: the whole image, without cropping. */
    public function test_gd_can_also_fit_inside_the_box_without_cropping(): void
    {
        $this->requireGd();

        Storage::disk('media')->put('invoices/photo.png', $this->png(60, 30));

        $result = (new GdConversionDriver($this->app['filesystem']))->convert(
            'media',
            'invoices/photo.png',
            'invoices/photo-preview.png',
            ['width' => 20, 'height' => 20, 'fit' => 'contain']
        );

        /* 60x30 into a 20x20 box: the width bounds, the height follows. */
        $this->assertSame(20, $result['width']);
        $this->assertSame(10, $result['height']);
    }

    /** ⚠️ AN UNREADABLE SOURCE RAISES: producing an empty thumbnail would be lying. */
    public function test_an_undecodable_source_raises(): void
    {
        $this->requireGd();

        Storage::disk('media')->put('invoices/fake.png', 'this is not an image');

        $this->expectException(\RuntimeException::class);

        (new GdConversionDriver($this->app['filesystem']))->convert(
            'media',
            'invoices/fake.png',
            'invoices/fake-thumb.png',
            []
        );
    }

    // ── The memory a decode costs ────────────────────────────────────────────

    /**
     * AN IMAGE TOO LARGE FOR THE MEMORY LEFT IS REFUSED, NOT SURVIVED.
     *
     * ⚠️ THE FAILURE THIS GUARDS IS NOT AN EXCEPTION, WHICH IS WHY AN ORDINARY TRY/CATCH NEVER
     * CAUGHT IT. `imagecreatefromstring` does not return `false` when there is no room: the
     * process dies where it stands. A command converting a whole library therefore stopped on
     * its first oversized file and left every later one untouched — observed on a host with a
     * 128 MB limit over uploads that predate any pixel ceiling.
     *
     * ⚠️ AND THE LIMIT IS LOWERED FOR REAL RATHER THAN DESCRIBED. `RuntimeLimits` is final and
     * reads `ini_get`; a doubled-up version of it would prove the test agrees with itself. The
     * cost is that the image must be built BEFORE the ceiling drops — building it after would
     * hit the very exhaustion being guarded against, and kill the run.
     *
     * ⚠️ THE SECOND HALF IS WHAT KEEPS THE FIRST HONEST. A guard that refused everything would
     * satisfy the refusal on its own; converting the same file once the room is back proves the
     * answer follows the memory and not the file.
     */
    public function test_an_image_larger_than_the_memory_left_is_refused_instead_of_exhausting_it(): void
    {
        $this->requireGd();

        /* 1200 x 1200 x 4 bytes is about 5.8 MB decoded, out of a file of a few kilobytes —
         * which is the whole point: nothing about the weight of the file announces it. */
        $canvas = imagecreatetruecolor(1200, 1200);
        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        Storage::disk('media')->put('wide.png', $bytes);

        $driver = new GdConversionDriver($this->app['filesystem']);
        $ceiling = ini_get('memory_limit');

        try {
            /* Four megabytes of room for an image that needs nearly six. */
            ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));

            $refused = null;

            try {
                $driver->convert('media', 'wide.png', 'wide-thumb.png', ['width' => 4, 'height' => 4]);
            } catch (\RuntimeException $e) {
                $refused = $e->getMessage();
            }

            $this->assertSame(
                'conversion_source_needs_more_memory',
                $refused,
                'An image that cannot fit in the memory left was not refused before being decoded.'
            );

            $this->assertFalse(
                Storage::disk('media')->exists('wide-thumb.png'),
                'A refused conversion still wrote a file.'
            );
        } finally {
            ini_set('memory_limit', (string) $ceiling);
        }

        $driver->convert('media', 'wide.png', 'wide-back.png', ['width' => 4, 'height' => 4]);

        $this->assertTrue(
            Storage::disk('media')->exists('wide-back.png'),
            'The same file was refused with the room back, so the guard does not follow the memory.'
        );
    }


    /**
     * THE MARGIN IS THE GUARD, NOT THE PIXEL COUNT.
     *
     * ⚠️ THIS IS THE CASE THE FIRST VERSION LET THROUGH. `width * height * 4` is what GD ends up
     * HOLDING; the decoder allocates row buffers, and for PNG an inflate window, on the way
     * there. A guard sized on the pixels alone therefore does its arithmetic, says yes, and the
     * process dies anyway — which is what happened in production on a 4997 x 2919 PNG weighing
     * 1.3 MB, against a 128 MB limit.
     *
     * ⚠️ SO THE BUDGET HERE SITS BETWEEN THE TWO ANSWERS ON PURPOSE: comfortably above
     * `width * height * 4`, comfortably below what a decode actually costs. A guard that weighed
     * pixels alone would accept; one that accounts for the decode refuses. Nothing else in this
     * file can tell those two apart.
     */
    public function test_the_refusal_accounts_for_what_a_decode_costs_beyond_its_pixels(): void
    {
        $this->requireGd();

        $canvas = imagecreatetruecolor(1200, 1200);
        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        Storage::disk('media')->put('dense.png', $bytes);

        $driver = new GdConversionDriver($this->app['filesystem']);
        $ceiling = ini_get('memory_limit');

        try {
            /* 1200 x 1200 x 4 is 5.8 MB of pixels. Eight megabytes of room is more than that
             * and less than the decode — the only window where the two guards disagree. */
            ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));

            $refused = null;

            try {
                $driver->convert('media', 'dense.png', 'dense-thumb.png', ['width' => 4, 'height' => 4]);
            } catch (\RuntimeException $e) {
                $refused = $e->getMessage();
            }

            $this->assertSame(
                'conversion_source_needs_more_memory',
                $refused,
                'A budget above the pixel count but below the cost of decoding it was accepted.'
            );
        } finally {
            ini_set('memory_limit', (string) $ceiling);
        }
    }

    /**
     * ⚠️ AN UNREADABLE HEADER IS REFUSED RATHER THAN HANDED ON. Where the dimensions cannot be
     * read there is nothing to weigh, and passing the file to the decoder is a guess that its
     * cost is small — a guess whose only outcome when wrong is a dead process.
     *
     * ⚠️ AND THIS TEST DOES NOT DISTINGUISH THE TWO IMPLEMENTATIONS, WHICH IS WORTH SAYING
     * PLAINLY. The decode refuses the same bytes with the same sentence, so it passes either
     * way; what it holds is the sentence itself, so that a later change cannot quietly turn a
     * refusal into a different answer. The reason for the guard is a memory failure that no
     * assertion can survive to observe.
     */
    public function test_bytes_with_no_readable_header_are_refused(): void
    {
        $this->requireGd();

        Storage::disk('media')->put('mystery.png', 'this is not an image at all');

        $driver = new GdConversionDriver($this->app['filesystem']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conversion_source_undecodable');

        $driver->convert('media', 'mystery.png', 'mystery-thumb.png', ['width' => 4, 'height' => 4]);
    }


    /**
     * IMAGICK IS NOT EXEMPT FROM THE CEILING, WHICH IS EASY TO BELIEVE AND WRONG.
     *
     * ⚠️ ITS PIXEL CACHE LIVES OUTSIDE THE PHP ALLOCATOR, so the natural reading is that PHP's
     * `memory_limit` does not apply to it and that `ImagickGuard` is enough. Measured on the
     * image that ended a production run — 4997 x 2919 — a `readImageBlob` moved PHP's OWN
     * accounting by 46 MB and took the peak to 106 of a 128 MB limit. `ImagickGuard` bounds what
     * ImageMagick spends on itself; it says nothing about the process hosting it.
     *
     * ⚠️ SO THE TWO DRIVERS ASK THE SAME QUESTION, and this test exists to keep them asking it.
     * A guard fitted to one of them is a guard the host loses by switching
     * `mediahub.images.driver` — a line of configuration away from the failure coming back.
     */
    public function test_imagick_refuses_an_image_larger_than_the_memory_left(): void
    {
        $this->requireImagick();

        $canvas = imagecreatetruecolor(1200, 1200);
        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        Storage::disk('media')->put('wide.png', $bytes);

        $driver = new ImagickConversionDriver($this->app['filesystem'], $this->app['config']);
        $ceiling = ini_get('memory_limit');

        try {
            ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));

            $refused = null;

            try {
                $driver->convert('media', 'wide.png', 'wide-thumb.png', ['width' => 4, 'height' => 4]);
            } catch (\RuntimeException $e) {
                $refused = $e->getMessage();
            }

            $this->assertSame(
                'conversion_source_needs_more_memory',
                $refused,
                'Imagick decoded an image that did not fit in the memory left.'
            );

            $this->assertFalse(
                Storage::disk('media')->exists('wide-thumb.png'),
                'A refused conversion still wrote a file.'
            );
        } finally {
            ini_set('memory_limit', (string) $ceiling);
        }

        /* ⚠️ THE COUNTERPART, WITHOUT WHICH THE ABOVE PROVES NOTHING: a guard that refused
         * everything would satisfy it just as well. */
        $driver->convert('media', 'wide.png', 'wide-back.png', ['width' => 4, 'height' => 4]);

        $this->assertTrue(
            Storage::disk('media')->exists('wide-back.png'),
            'The same file was refused with the room back, so the guard does not follow the memory.'
        );
    }

}
