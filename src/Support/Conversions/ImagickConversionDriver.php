<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Support\ImagickGuard;

/**
 * THE IMAGICK DRIVER — for whoever has it, and for what GD cannot do.
 *
 * ⚠️ IT READS MORE FORMATS, PDF and TIFF among them. That is its reason to exist: GD sees no
 * further than the common raster images.
 *
 * ⚠️ AND IT HONESTLY DECLARES THAT IT CAN DO NOTHING when the extension is absent — rather
 * than raising on the first upload. The contract is written for that.
 */
final class ImagickConversionDriver implements ConversionDriver
{
    /**
     * THE FORMAT -> THE NAME IMAGEMAGICK GIVES IT. What to ask for, not what to answer.
     *
     * @var array<string, string>
     */
    private const FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/gif' => 'GIF',
        'image/webp' => 'WEBP',
        'image/bmp' => 'BMP',
        'image/tiff' => 'TIFF',
        'image/heic' => 'HEIC',
        'image/avif' => 'AVIF',
        'application/pdf' => 'PDF',
    ];

    /**
     * ⚠️ HELD FOR THE LIFETIME OF THE PROCESS. Neither ImageMagick's policy, nor the compiled
     * delegates, nor the installed binaries change while it runs; probing on every call would
     * start one Ghostscript per requested thumbnail.
     *
     * @var array<string, bool>
     */
    private static array $proven = [];

    private readonly DecodeBudget $budget;

    /**
     * ⚠️ THE BUDGET IS AN ARGUMENT SO IT CAN BE DESCRIBED. What is left of `memory_limit` on the
     * machine running a test is not something a test can arrange, and behaviour that depends on
     * it is otherwise only provable on the host where it already goes wrong.
     */
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly Config $config,
        ?DecodeBudget $budget = null,
    ) {
        $this->budget = $budget ?? new DecodeBudget();
    }

    /**
     * ⚠️ `queryFormats()` ANNOUNCES, IT DOES NOT GUARANTEE — and this is observed, not assumed.
     * On a host without Ghostscript it answers "yes" for PDF, and "yes" for HEIC and AVIF while
     * ImageMagick has no working libheif. Three formats announced, three formats unavailable. A
     * distribution that forbids PDF in `policy.xml` produces the same gap, for a different
     * reason.
     *
     * ⚠️ SO WE CONFIRM WITH A REAL ROUND TRIP: build an 8×8 in that format, then read it back.
     * A delegate that can do neither is an absent delegate, whatever the list says. It is
     * measured once per format per process — 115 ms for the nine formats when measured, and in
     * production only the ones actually requested are probed.
     *
     * ⚠️ THIS PROBE CAN BE WRONG IN ONE DIRECTION, AND IT IS THE RIGHT ONE: a build that can
     * READ a format without being able to WRITE it will be declared incapable. We lose a
     * thumbnail, we promise nothing false, and the original is still served.
     *
     * ⚠️ AND AS WITH GD, WRITING THE DERIVATIVE MUST WORK TOO: outputs go through PNG.
     */
    public function supports(string $mimeType): bool
    {
        if (! ImagickGuard::available()) {
            return false;
        }

        $format = self::FORMATS[strtolower($mimeType)] ?? null;

        return $format !== null && $this->usable($format) && $this->usable('PNG');
    }

    /**
     * WHAT WE AGREE TO HAND BACK AS IS.
     *
     * ⚠️ THE LIST IS SHORT ON PURPOSE. Imagick reads HEIC and TIFF, but a HEIC thumbnail
     * displays in almost no browser: a derivative is made to be LOOKED AT, it has no business
     * inheriting a format the source could afford.
     *
     * @var array<int, string>
     */
    private const DIRECT_OUTPUTS = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * ⚠️ A PDF DOES NOT YIELD A PDF THUMBNAIL: the rendered page is an image, and its file must
     * say so. The same goes for TIFF and HEIC, for another reason — they can be read but not
     * looked at.
     */
    public function outputMimeType(string $sourceMimeType): string
    {
        $mime = strtolower(trim($sourceMimeType));

        if (! in_array($mime, self::DIRECT_OUTPUTS, true)) {
            return 'image/png';
        }

        return $this->usable(self::FORMATS[$mime]) ? $mime : 'image/png';
    }

    /**
     * ⚠️ WHEN IN DOUBT, WE ANSWER NO. Being wrong in that direction costs a thumbnail and
     * leaves the original served; being wrong the other way puts an image that will never exist
     * into a listing.
     *
     * ⚠️ AND THE PROBE IS BOUNDED WITH ITS OWN VALUES, not the host's: it builds its 8×8, it
     * has nothing to protect itself from. Bounding it with a tight setting would make it fail
     * on a machine that reads the format perfectly well, and the package would wrongly conclude
     * it is unavailable.
     */
    private function usable(string $format): bool
    {
        if (array_key_exists($format, self::$proven)) {
            return self::$proven[$format];
        }

        try {
            $writer = new \Imagick();
            ImagickGuard::bound($writer, ImagickGuard::PROBE);
            $writer->newImage(8, 8, new \ImagickPixel('rgb(10,120,200)'));
            $writer->setImageFormat($format);
            $bytes = $writer->getImageBlob();
            $writer->clear();

            if ($bytes === '') {
                throw new \RuntimeException('format_probe_empty');
            }

            $reader = new \Imagick();
            ImagickGuard::bound($reader, ImagickGuard::PROBE);
            $reader->readImageBlob($bytes);
            $readable = $reader->getImageWidth() > 0;
            $reader->clear();

            return self::$proven[$format] = $readable;
        } catch (\Throwable) {
            return self::$proven[$format] = false;
        }
    }

    /**
     * @param  array{width?: int, height?: int, fit?: string}  $definition
     * @return array<string, mixed>
     */
    public function convert(string $disk, string $path, string $target, array $definition): array
    {
        $storage = $this->filesystems->disk($disk);

        $bytes = $storage->get($path);

        if ($bytes === null || $bytes === '') {
            throw new \RuntimeException('conversion_source_unreadable');
        }

        /*
         * ⚠️ THE HEADER FIRST, AND THE SAME QUESTION AS THE OTHER DRIVER. `pingImageBlob` reads
         * dimensions without expanding anything; asking after the read would be asking once the
         * damage is done.
         *
         * ⚠️ IMAGICK IS NOT EXEMPT FROM `memory_limit`, WHICH IS EASY TO BELIEVE AND WRONG. Its
         * pixel cache lives outside the PHP allocator, so the natural reading is that PHP's
         * ceiling does not apply. Measured on the image that killed a production run — 4997 x
         * 2919 — a `readImageBlob` moved PHP's own accounting by 46 MB and took the peak to 106
         * of a 128 MB limit. `ImagickGuard` bounds what ImageMagick spends on itself; it says
         * nothing about the process that hosts it.
         */
        try {
            $probe = new \Imagick();
            ImagickGuard::bound($probe, ImagickGuard::limits($this->config));
            $probe->pingImageBlob($bytes);

            $width = $probe->getImageWidth();
            $height = $probe->getImageHeight();
            $probe->clear();
        } catch (\Throwable $e) {
            throw new \RuntimeException('conversion_source_undecodable', 0, $e);
        }

        $this->budget->refuse((int) $width, (int) $height);

        /**
         * ⚠️ THE SAME FAILURE AS THE OTHER DRIVERS, OF THE SAME TYPE. Imagick raises its own
         * exception; letting it through as is would force every caller to know which driver is
         * in service in order to know what to catch.
         */
        try {
            /** @var \Imagick $image */
            $image = new \Imagick();

            /* ⚠️ BOUNDS BEFORE THE READ: afterwards the image is already expanded in memory. */
            ImagickGuard::bound($image, ImagickGuard::limits($this->config));

            $image->readImageBlob($bytes);
        } catch (\Throwable $e) {
            throw new \RuntimeException('conversion_source_undecodable', 0, $e);
        }

        /* ⚠️ THE FIRST PAGE ONLY: a PDF has several, a thumbnail wants one. */
        $image->setIteratorIndex(0);

        $width = (int) ($definition['width'] ?? 256);
        $height = (int) ($definition['height'] ?? 256);

        if (($definition['fit'] ?? 'cover') === 'contain') {
            $image->thumbnailImage($width, $height, true);
        } else {
            $image->cropThumbnailImage($width, $height);
        }

        $header = @getimagesizefromstring($bytes);
        $output = $this->outputMimeType(is_array($header) ? (string) ($header['mime'] ?? '') : '');

        $image->setImageFormat(match ($output) {
            'image/jpeg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        });

        $buffer = $image->getImageBlob();

        $result = [
            'path' => $target,
            'disk' => $disk,
            'mime_type' => $output,
            'width' => $image->getImageWidth(),
            'height' => $image->getImageHeight(),
            'size' => strlen($buffer),
        ];

        $image->clear();

        $storage->put($target, $buffer);

        return $result;
    }

    /** ⚠️ ImageMagick is used through its extension here, not through its command line. */
    public function needsAProgram(): bool
    {
        return false;
    }

}
