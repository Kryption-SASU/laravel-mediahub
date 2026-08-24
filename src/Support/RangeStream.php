<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SERVING BYTES — streamed, and honouring `Range`.
 *
 * ⚠️ `Range` IS NOT A COMFORT, IT IS WHAT MAKES VIDEO USABLE. Without it, a player cannot seek
 * within a video: it has to download everything again from the start. Safari goes further — it
 * begins by asking for `bytes=0-1` and refuses to play if the response is not a 206. A server
 * that ignores the header therefore does not "degrade" the experience on iOS: it does not play.
 *
 * ⚠️ AND EVERYTHING GOES THROUGH `readStream`, NEVER THROUGH A FULL READ. With an upload
 * ceiling of 200 MB, `Storage::get()` puts the entire file in memory: that is the difference
 * between serving ten videos at once and killing the process on the first.
 *
 * ⚠️ THE ORIGINAL MODULE, FOR ITS PART, TESTED `file_exists(public_path($file->url))` while
 * `url` is an accessor returning a FULL URL. The test always failed: downloading had been
 * broken since the move to the cloud, and nobody saw it.
 */
final class RangeStream
{
    /** 256 KiB: large enough not to multiply round trips, small enough to accumulate nothing. */
    private const BLOCK = 262144;

    public function __construct(private readonly FilesystemFactory $filesystems)
    {
    }

    public function respond(
        Request $request,
        string $disk,
        string $path,
        string $mimeType,
        string $fileName,
        int $size,
        bool $attachment = false,
    ): Response {
        $storage = $this->filesystems->disk($disk);

        $total = $size > 0 ? $size : (int) $storage->size($path);

        $headers = [
            'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $attachment ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
                $this->safeName($fileName),
                $this->asciiName($fileName),
            ),
            /* ⚠️ WITHOUT THIS HEADER, THE CLIENT DOES NOT EVEN ASK FOR A RANGE. */
            'Accept-Ranges' => 'bytes',
        ];

        $range = $this->range($request->headers->get('Range'), $total);

        if ($range === false) {
            /*
             * ⚠️ A 416 WITHOUT `Content-Range` DOES NOT TELL THE CLIENT WHAT IT SHOULD HAVE
             * ASKED FOR, and it retries the same thing indefinitely.
             */
            return new Response('', 416, $headers + ['Content-Range' => 'bytes */'.$total]);
        }

        [$start, $end, $partial] = $range;

        $headers['Content-Length'] = (string) ($end - $start + 1);

        if ($partial) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$total;
        }

        return new StreamedResponse(
            fn () => $this->pipe($storage, $path, $start, $end),
            $partial ? 206 : 200,
            $headers,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: bool}|false  false: unsatisfiable range
     */
    private function range(?string $header, int $total): array|false
    {
        $whole = [0, max(0, $total - 1), false];

        if ($header === null || $total <= 0) {
            return $whole;
        }

        if (! preg_match('/^bytes=(.+)$/i', trim($header), $found)) {
            return $whole;
        }

        $parts = explode(',', $found[1]);

        /*
         * ⚠️ ONE RANGE ONLY. Several would call for a `multipart/byteranges` response, which
         * nothing we serve asks for — and the standard explicitly allows answering with the
         * whole file. Better a correct 200 than a shaky multipart.
         */
        if (count($parts) !== 1) {
            return $whole;
        }

        if (! preg_match('/^(\d*)-(\d*)$/', trim($parts[0]), $bounds)) {
            return $whole;
        }

        [$start, $end] = [$bounds[1], $bounds[2]];

        if ($start === '' && $end === '') {
            return $whole;
        }

        if ($start === '') {
            /* `bytes=-500`: the LAST 500 bytes. */
            $length = min((int) $end, $total);

            return $length <= 0 ? false : [$total - $length, $total - 1, true];
        }

        $start = (int) $start;

        /*
         * ⚠️ ONE COMPARISON IS ENOUGH, AND THAT IS A MEASUREMENT. A "does the start exceed the
         * size?" check had also been written here; replacing it with `false` left the suite
         * entirely green. Since the end bound is clamped to `$total - 1`, any start outside the
         * file necessarily falls back on `$end < $start`. A guard no mutation wakes up is not a
         * guard, it is a line to maintain.
         */
        $end = $end === '' ? $total - 1 : min((int) $end, $total - 1);

        return $end < $start ? false : [$start, $end, true];
    }

    private function pipe(mixed $storage, string $path, int $start, int $end): void
    {
        $handle = $storage->readStream($path);

        if (! is_resource($handle)) {
            return;
        }

        try {
            $this->seek($handle, $start);

            $remaining = $end - $start + 1;

            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, (int) min(self::BLOCK, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;

                $remaining -= strlen($chunk);

                /*
                 * ⚠️ WE ONLY FLUSH IF THERE IS NO OTHER BUFFER ABOVE. The bench captures the
                 * response by opening its own buffer: calling a flush inside it would send the
                 * bytes past it, and the test would read an empty response while nothing is
                 * broken in production.
                 */
                if (ob_get_level() === 0) {
                    flush();
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * ⚠️ NOT EVERY STREAM IS SEEKABLE. A remote object often arrives read-only and sequential:
     * `fseek` fails on it, and the bytes have to be consumed to get past them. It is slower,
     * but it is the difference between "playback skips" and "the response hands back the start
     * of the file while claiming it is the middle".
     */
    private function seek(mixed $handle, int $start): void
    {
        if ($start <= 0) {
            return;
        }

        if (@fseek($handle, $start) === 0) {
            return;
        }

        $remaining = $start;

        while ($remaining > 0 && ! feof($handle)) {
            $chunk = fread($handle, (int) min(self::BLOCK, $remaining));

            if ($chunk === false || $chunk === '') {
                return;
            }

            $remaining -= strlen($chunk);
        }
    }

    /**
     * ⚠️ THE DISPLAYED NAME IS A STRING TYPED BY A HUMAN. A slash, a carriage return or a null
     * byte in it, and the header becomes either invalid or a header injection. Symfony refuses
     * those characters by raising: better to strip them here, where we know what to put in
     * their place.
     */
    private function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', '"', "\r", "\n", "\0"], '', $name);
        $name = trim($name);

        return $name === '' ? 'media' : $name;
    }

    private function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $this->safeName($name));
        $ascii = trim((string) $ascii);

        return $ascii === '' ? 'media' : $ascii;
    }
}
