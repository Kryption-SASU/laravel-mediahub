<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Support\RuntimeLimits;

/**
 * THE DEFAULT DRIVER — GD, because it is almost always there.
 *
 * ⚠️ "ALMOST" IS NOT "ALWAYS", AND THAT IS THE WHOLE POINT OF `supports()`. A minimal runtime
 * image has no GD; a host that only stores documents does not need it. A package that required
 * it would not install for them — when it serves them perfectly well without thumbnails.
 *
 * ⚠️ AND AN IMPOSSIBLE DERIVATIVE NEVER PREVENTS THE ORIGINAL FROM BEING SERVED. That is the
 * rule shaping this contract: the driver answers "I cannot" instead of raising.
 */
final class GdConversionDriver implements ConversionDriver
{
    /**
     * THE FORMAT -> THE CAPABILITY GD MUST DECLARE FOR IT.
     *
     * ⚠️ THIS TABLE DOES NOT SAY WHAT GD CAN DO, IT SAYS WHERE TO ASK IT. The answer comes from
     * `gd_info()`, at runtime, on the machine that is running.
     *
     * @var array<string, string>
     */
    private const CAPABILITIES = [
        'image/jpeg' => 'JPEG Support',
        'image/png' => 'PNG Support',
        'image/gif' => 'GIF Read Support',
        'image/webp' => 'WebP Support',
        'image/avif' => 'AVIF Support',
        'image/bmp' => 'BMP Support',
    ];

    /**
     * WHAT ONE PIXEL COSTS ONCE DECODED.
     *
     * ⚠️ FOUR BYTES WHATEVER THE FILE WEIGHED. GD holds a truecolour image at a fixed cost per
     * pixel, so a fifty-megapixel photograph is two hundred megabytes of memory out of a file of
     * six. Sizing anything on the weight of the file describes a different quantity.
     */
    private const BYTES_A_PIXEL = 4;

    /**
     * ⚠️ A MARGIN, AND IT IS CALLED ONE RATHER THAN MEASURED. `width * height * 4` is the size of
     * the image GD ends up holding, not the peak it passes through: the decoder allocates row
     * buffers of its own on the way. A tenth is a judgement, not a measurement — its purpose is
     * to keep the refusal on the safe side of a boundary nobody can name exactly.
     */
    private const MEMORY_MARGIN = 1.1;

    private readonly GdCapabilities $gd;

    private readonly RuntimeLimits $limits;

    /**
     * ⚠️ THE CAPABILITY SOURCE IS RESOLVED ONCE, AT CONSTRUCTION. Neither the compiled
     * libraries nor the loaded extensions change while the process runs, and `supports()` is
     * called for every upload: asking the runtime each time would cost for an answer that
     * cannot move.
     *
     * ⚠️ AND IT IS AN ARGUMENT, NOT A CALL BURIED IN THE METHOD. That is what makes "the answer
     * follows the build" provable on any machine — see `GdCapabilities`.
     */
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        ?GdCapabilities $capabilities = null,
        ?RuntimeLimits $limits = null,
    ) {
        $this->gd = $capabilities ?? GdCapabilities::fromRuntime();
        $this->limits = $limits ?? new RuntimeLimits();
    }

    /**
     * ⚠️ "GD IS LOADED" DOES NOT MEAN "GD CAN READ THIS FORMAT". GD is built à la carte:
     * without libwebp it ignores WebP, without libjpeg it ignores JPEG, and AVIF additionally
     * demands libavif and PHP 8.1. Two hosts with the same PHP version and the same extension
     * loaded therefore do not read the same files.
     *
     * ⚠️ AND THIS IS NOT A TEXTBOOK CASE. An earlier version of this method consulted a
     * hardcoded list, commented "whatever its compile options": it promised WebP on a build
     * without libwebp, and kept quiet about AVIF where it was available. Both directions were
     * observed on real hosts.
     *
     * ⚠️ THE ANSWER COMES FROM `GdCapabilities`, WHICH IS INJECTED — and that is what makes the
     * absence of such a list provable. A stripped build can be described in one line, on any
     * machine, and a list matching the runner is then caught.
     *
     * ⚠️ WRITING MATTERS AS MUCH AS READING. Derivatives come out as PNG: without that
     * capability, being able to decode the source leads nowhere.
     */
    public function supports(string $mimeType): bool
    {
        $capability = self::CAPABILITIES[strtolower($mimeType)] ?? null;

        if ($capability === null) {
            return false;
        }

        return $this->gd->has($capability) && $this->gd->has('PNG Support');
    }

    /**
     * ⚠️ THE DERIVATIVE KEEPS ITS SOURCE'S FORMAT WHEN GD CAN WRITE IT. A JPEG yields a JPEG:
     * pushing everything to PNG would multiply the weight of photographs, and would make the
     * produced file's extension lie.
     *
     * ⚠️ AND BEING ABLE TO READ IS NOT BEING ABLE TO WRITE — for GIF, GD distinguishes the two
     * itself with two separate flags. PNG is the fallback, and it is announced, not suffered.
     */
    public function outputMimeType(string $sourceMimeType): string
    {
        $mime = strtolower(trim($sourceMimeType));

        $writable = match ($mime) {
            'image/jpeg' => $this->gd->has('JPEG Support'),
            'image/webp' => $this->gd->has('WebP Support'),
            'image/gif' => $this->gd->has('GIF Create Support'),
            'image/png' => $this->gd->has('PNG Support'),
            default => false,
        };

        return $writable ? $mime : 'image/png';
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

        $this->refuseWhatCannotFitInMemory($bytes);

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new \RuntimeException('conversion_source_undecodable');
        }

        try {
            $thumbnail = $this->resize(
                $source,
                (int) ($definition['width'] ?? 256),
                (int) ($definition['height'] ?? 256),
                (string) ($definition['fit'] ?? 'cover')
            );

            /*
             * ⚠️ THE SOURCE'S TYPE IS READ FROM ITS BYTES, not from what the caller says about
             * it: this method receives only a path, and an extension is only a name.
             */
            $header = @getimagesizefromstring($bytes);

            $encoded = $this->encode($thumbnail, is_array($header) ? (string) ($header['mime'] ?? '') : '');

            $storage->put($target, $encoded['bytes']);

            return [
                'path' => $target,
                'disk' => $disk,
                'mime_type' => $encoded['mime'],
                'width' => imagesx($thumbnail),
                'height' => imagesy($thumbnail),
                'size' => strlen($encoded['bytes']),
            ];
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * ⚠️ `cover` FILLS AND CROPS, `contain` FITS INSIDE THE BOX. Both exist because a list
     * thumbnail wants a regular frame, and a preview wants the whole image. Confusing the two
     * gives either letterboxing or cropped faces.
     */
    /**
     * REFUSING AN IMAGE THAT WILL NOT FIT, BEFORE TRYING TO DECODE IT.
     *
     * ⚠️ AN EXHAUSTION IS NOT AN EXCEPTION, AND THAT IS THE WHOLE REASON THIS EXISTS.
     * `imagecreatefromstring` does not return `false` when there is no room left: the process
     * dies where it stands. Nothing is caught, no row is marked failed, and a command converting
     * a library stops on its first oversized file with every later one untouched. Measured on a
     * host with a 128 MB limit over a library of legacy uploads — files that predate the
     * `uploads.max_image_pixels` ceiling and were never checked against any.
     *
     * ⚠️ SO THE QUESTION IS ASKED OF THE HEADER, NOT OF THE DECODER. `getimagesizefromstring`
     * reads dimensions without allocating the image, which is the only way to know the cost
     * before paying it.
     *
     * ⚠️ AND AGAINST WHAT IS LEFT, NOT AGAINST THE LIMIT. A 256 MB limit with 200 MB already in
     * use is a 56 MB budget; comparing against the limit passes, then exhausts.
     */
    private function refuseWhatCannotFitInMemory(string $bytes): void
    {
        $left = $this->limits->memoryLeft();

        /* ⚠️ UNBOUNDED IS NOT "VERY LARGE", IT IS "NO CEILING TO HIT". Refusing on a machine that
         * has no limit would invent a failure the host never asked for. */
        if ($left === null) {
            return;
        }

        $size = @getimagesizefromstring($bytes);

        /* ⚠️ AN UNREADABLE HEADER IS NOT THIS METHOD'S BUSINESS. The decode below already answers
         * it, with its own sentence; guessing here would report the wrong cause. */
        if ($size === false) {
            return;
        }

        $needed = (int) ($size[0] * $size[1] * self::BYTES_A_PIXEL * self::MEMORY_MARGIN);

        if ($needed >= $left) {
            throw new \RuntimeException('conversion_source_needs_more_memory');
        }
    }

    private function resize(\GdImage $source, int $width, int $height, string $mode): \GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new \RuntimeException('conversion_source_empty');
        }

        $factor = $mode === 'contain'
            ? min($width / $sourceWidth, $height / $sourceHeight)
            : max($width / $sourceWidth, $height / $sourceHeight);

        $scaledWidth = max(1, (int) round($sourceWidth * $factor));
        $scaledHeight = max(1, (int) round($sourceHeight * $factor));

        $scaled = imagecreatetruecolor($scaledWidth, $scaledHeight);
        $this->preserveTransparency($scaled);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $sourceWidth, $sourceHeight);

        if ($mode === 'contain') {
            return $scaled;
        }

        $cropped = imagecreatetruecolor(min($width, $scaledWidth), min($height, $scaledHeight));
        $this->preserveTransparency($cropped);

        imagecopy(
            $cropped,
            $scaled,
            0,
            0,
            (int) max(0, ($scaledWidth - $width) / 2),
            (int) max(0, ($scaledHeight - $height) / 2),
            imagesx($cropped),
            imagesy($cropped)
        );

        imagedestroy($scaled);

        return $cropped;
    }

    /**
     * ⚠️ TRANSPARENCY IS LOST WITHOUT THESE THREE LINES, and the defect only shows on images
     * that have it: a logo turns black on dark screens, and nobody understands why.
     */
    private function preserveTransparency(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    private function encode(\GdImage $image, string $source): array
    {
        $mime = $this->outputMimeType($source);

        ob_start();

        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 85),
            'image/webp' => imagewebp($image),
            'image/gif' => imagegif($image),
            default => imagepng($image),
        };

        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** ⚠️ GD is an extension of the running process; nothing is spawned. */
    public function needsAProgram(): bool
    {
        return false;
    }

}
