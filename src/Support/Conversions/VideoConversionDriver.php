<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Exceptions\NothingToDraw;
use Kryption\MediaHub\Support\ExternalTools;

/**
 * A STILL OUT OF A VIDEO — one frame, chosen, not the first one.
 *
 * ⚠️ THE FIRST FRAME IS ALMOST ALWAYS BLACK. Films fade in, phone recordings start on a lens cap
 * or a ceiling, and screen captures start on an empty desktop: a library of videos thumbnailed at
 * zero seconds is a grid of black squares, which is worse than the type icon it replaced. The
 * second to capture is therefore a setting, and its default is a few seconds in.
 *
 * ⚠️ AND A CAPTURE PAST THE END PRODUCES NOTHING AT ALL, SILENTLY. ffmpeg seeks, finds no frame,
 * writes no file and exits without complaint — so a two-second clip asked for at three seconds
 * would fail with no failure anywhere. The length is read first and the request brought inside
 * it, which is what ffprobe is for here.
 *
 * ⚠️ NOTHING IS RUN THROUGH A SHELL, and the source is always a local path — never a URL. See
 * {@see ExternalTools} and {@see SourceFile} for both, and for why the second is a safety
 * decision rather than only a technical one.
 */
final class VideoConversionDriver implements ConversionDriver
{
    /** ⚠️ A JPEG: a frame is a photograph, and a PNG of one is five times the weight for nothing. */
    private const OUTPUT = 'image/jpeg';

    /**
     * ⚠️ A THUMBNAIL IS NOT WORTH A MINUTE. Seeking in a badly indexed file can be slow, and a
     * queue worker held on one video is a queue that stops moving.
     */
    private const SECONDS = 30;

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly Config $config,
        private readonly ExternalTools $tools,
        private readonly SourceFile $sources,
    ) {
    }

    /**
     * ⚠️ THE ANSWER DEPENDS ON THE MACHINE, NOT ON THE FILE ALONE. Without ffmpeg there is
     * nothing to be done with a video, and saying so here is what stops a row being created for
     * a derivative that will never exist — a "failed" mark sends somebody looking for a failure
     * where there was simply nothing to do.
     */
    public function supports(string $mimeType): bool
    {
        return str_starts_with(strtolower($mimeType), 'video/')
            && $this->tools->has(ExternalTools::FFMPEG);
    }

    public function outputMimeType(string $sourceMimeType): string
    {
        return self::OUTPUT;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function convert(string $disk, string $path, string $target, array $definition): array
    {
        $ffmpeg = $this->tools->path(ExternalTools::FFMPEG);

        if ($ffmpeg === null) {
            throw new \RuntimeException('conversion_tool_missing');
        }

        $width = max(1, (int) ($definition['width'] ?? 256));
        $height = max(1, (int) ($definition['height'] ?? 256));
        $fit = (string) ($definition['fit'] ?? 'cover');

        return $this->sources->withLocalCopy($disk, $path, function (string $local) use (
            $ffmpeg, $disk, $target, $width, $height, $fit
        ): array {
            $streams = $this->picturesIn($local);

            /*
             * ⚠️ A VIDEO TYPE IS NOT A PROMISE OF A PICTURE, and the `.wma` is the case that
             * proves it: ASF is one container for both, so `finfo` answers `video/x-ms-asf` for
             * a file that is purely audio. There is nothing to draw, and saying "failed" would
             * send somebody looking for a fault that does not exist.
             */
            if ($streams === 0) {
                throw new NothingToDraw('conversion_no_picture');
            }

            $at = $this->secondToCapture($local);

            return $this->sources->withScratchFile('jpg', function (string $frame) use (
                $ffmpeg, $local, $at, $disk, $target, $width, $height, $fit
            ): array {
                $answer = $this->tools->run([
                    $ffmpeg,
                    /* ⚠️ NOTHING IS TYPED AT IT: ffmpeg asks before overwriting, and a question
                     * nobody answers is a worker held until the timeout. */
                    '-nostdin',
                    '-v', 'error',

                    /*
                     * ⚠️ ONLY THE LOCAL FILE PROTOCOL. ffmpeg speaks http, rtmp and a dozen
                     * others; a crafted file can name another input, and this is what stops it
                     * being followed.
                     */
                    '-protocol_whitelist', 'file',

                    /* ⚠️ BEFORE `-i`, WHICH IS THE FAST SEEK. After it, ffmpeg decodes every
                     * frame from the start — minutes of work for one picture. */
                    '-ss', (string) $at,
                    '-i', $local,
                    '-frames:v', '1',
                    '-vf', $this->filter($width, $height, $fit),
                    '-f', 'image2',
                    '-y', $frame,
                ], self::SECONDS);

                /*
                 * ⚠️ THE FILE IS CHECKED, NOT THE EXIT CODE. ffmpeg can exit zero having written
                 * nothing — a seek past the last frame does exactly that — and an empty
                 * thumbnail stored as a success is a broken image on the screen for ever.
                 */
                $bytes = is_file($frame) ? (string) file_get_contents($frame) : '';

                if ($bytes === '') {
                    /*
                     * ⚠️ WITH FFPROBE, A PICTURE WAS SEEN AND NOT PRODUCED — that is a fault, and
                     * it is recorded as one. Without it, nothing here has any evidence either
                     * way, and claiming a failure on no evidence is how a library fills with
                     * red marks nobody can act on.
                     */
                    if ($this->tools->has(ExternalTools::FFPROBE)) {
                        throw new \RuntimeException('conversion_frame_empty: '.trim($answer['err']));
                    }

                    throw new NothingToDraw('conversion_frame_empty');
                }

                $this->filesystems->disk($disk)->put($target, $bytes);

                $size = @getimagesizefromstring($bytes);

                return [
                    'path' => $target,
                    'disk' => $disk,
                    'mime_type' => self::OUTPUT,
                    'width' => is_array($size) ? (int) $size[0] : null,
                    'height' => is_array($size) ? (int) $size[1] : null,
                    'size' => strlen($bytes),
                ];
            });
        });
    }

    /**
     * HOW MANY PICTURE STREAMS THE FILE HOLDS — asked, not assumed from its type.
     *
     * ⚠️ WITHOUT FFPROBE THE ANSWER IS "UNKNOWN", AND UNKNOWN IS NOT ZERO. Returning zero would
     * have every video on such a machine declared pictureless and skipped in silence; returning
     * one lets ffmpeg try, and the emptiness of what it writes decides instead.
     */
    private function picturesIn(string $local): int
    {
        $ffprobe = $this->tools->path(ExternalTools::FFPROBE);

        if ($ffprobe === null) {
            return 1;
        }

        $answer = $this->tools->run([
            $ffprobe,
            '-v', 'error',
            '-select_streams', 'v',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            $local,
        ], self::SECONDS);

        /*
         * ⚠️ THE EMPTINESS IS TESTED EXPLICITLY, AND `array_filter` WOULD NOT DO. Its default
         * callback drops anything falsy, and the first stream of a file is index `0` — the
         * commonest answer there is. Every ordinary video therefore came back as "no picture in
         * it", was declined in silence, and left no row and no trace. Caught by a probe, not by
         * reading.
         */
        $lines = array_filter(
            explode("\n", trim($answer['out'])),
            static fn (string $line): bool => trim($line) !== '',
        );

        return count($lines);
    }

    /**
     * WHERE IN THE FILE TO LOOK, BROUGHT INSIDE THE FILE.
     *
     * ⚠️ ASKED OF FFPROBE, AND FORGIVING WHEN IT IS NOT THERE. Without it the configured second
     * is used as it stands: a capture past the end then produces nothing, and the health report
     * says why. Refusing to make any thumbnail at all for want of ffprobe would punish every
     * video for a risk that only concerns the short ones.
     */
    private function secondToCapture(string $local): float
    {
        $wanted = max(0.0, (float) $this->config->get('mediahub.video.frame_at', 3));
        $ffprobe = $this->tools->path(ExternalTools::FFPROBE);

        if ($ffprobe === null) {
            return $wanted;
        }

        $answer = $this->tools->run([
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $local,
        ], self::SECONDS);

        $duration = (float) trim($answer['out']);

        if ($duration <= 0.0) {
            return $wanted;
        }

        /*
         * ⚠️ NOT THE LAST FRAME EITHER. Landing exactly on the end is the same silence as landing
         * past it, and the final frame of a video is as often black as the first.
         */
        return $wanted < $duration ? $wanted : $duration / 2;
    }

    /**
     * ⚠️ THE SCALING IS FFMPEG'S, so the frame is never decoded twice. `increase` then `crop`
     * fills the box the way a grid of tiles wants; `decrease` fits inside it without cutting.
     */
    private function filter(int $width, int $height, string $fit): string
    {
        if ($fit === 'contain') {
            return sprintf('scale=%d:%d:force_original_aspect_ratio=decrease', $width, $height);
        }

        return sprintf(
            'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
            $width,
            $height,
            $width,
            $height,
        );
    }
}
