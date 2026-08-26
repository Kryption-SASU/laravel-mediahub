<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Kryption\MediaHub\Support\RuntimeLimits;

/**
 * WHETHER AN IMAGE CAN BE EXPANDED IN THE MEMORY THAT IS LEFT.
 *
 * ⚠️ AN EXHAUSTION IS NOT AN EXCEPTION, WHICH IS THE WHOLE REASON THIS EXISTS. Neither
 * `imagecreatefromstring` nor `Imagick::readImageBlob` returns `false` when there is no room:
 * the process dies where it stands. Nothing is caught, no row is marked failed, and a command
 * converting a library stops on its first oversized file with every later one untouched.
 * Measured in production on a 4997 x 2919 PNG weighing 1.3 MB, against a 128 MB limit.
 *
 * ⚠️ AND IT IS THE PIXELS THAT COST, NOT THE WEIGHT OF THE FILE. A decoded image is four bytes
 * a pixel whatever it compressed to, so a fifty-megapixel photograph is two hundred megabytes
 * out of a file of six. Anything sized on the file describes a different quantity.
 *
 * ⚠️ IT IS SHARED BECAUSE BOTH DRIVERS PAY IN THE SAME CURRENCY. Imagick keeps its pixel cache
 * outside the PHP allocator, which is easy to read as "outside the limit" — it is not. Measured
 * on that same image: a `readImageBlob` moved PHP's own accounting by 46 MB and took the peak to
 * 106 MB of a 128 MB limit. One rule, asked by both, is the only way the two cannot drift.
 */
final class DecodeBudget
{
    /**
     * WHAT ONE PIXEL COSTS ONCE DECODED.
     *
     * ⚠️ FOUR BYTES, WHATEVER THE FILE WEIGHED. Both libraries hold a truecolour image at a
     * fixed cost per pixel; the compression ratio of the source says nothing about it.
     */
    public const BYTES_A_PIXEL = 4;

    /**
     * HOW MUCH MORE THAN `width * height * 4` A DECODE ACTUALLY COSTS.
     *
     * ⚠️ MEASURED, AND THE FIRST ATTEMPT AT THIS NUMBER WAS NOT. A tenth was picked as a
     * judgement and shipped; it let through the very image described above, which then exhausted
     * its limit exactly as if no guard existed. The guard had run, done its arithmetic, and said
     * yes.
     *
     * ⚠️ THE PEAK IS NOT THE IMAGE. `width * height * 4` is what the library ends up HOLDING;
     * the decoder allocates row buffers, and for PNG an inflate window, on the way there. Peak
     * against that figure, over six cases from 5.8 MB to 35 MB, baseline and progressive JPEG
     * and PNG:
     *
     *     jpeg 1200x1200   1.46      png 2400x1800   1.58
     *     jpeg 2400x1800   1.09      png 3500x2500   0.84
     *     jpeg progressive 0.97      jpeg 3500x2500  0.96
     *
     * Two is the worst of those with room above it, not an average: the cost of refusing an
     * image that would have fitted is a missing thumbnail, and the cost of accepting one that
     * does not is the whole run.
     */
    public const MARGIN = 2.0;

    public function __construct(private readonly RuntimeLimits $limits = new RuntimeLimits())
    {
    }

    /**
     * @throws \RuntimeException when the decode would not fit in what is left
     */
    public function refuse(int $width, int $height): void
    {
        $left = $this->limits->memoryLeft();

        /* ⚠️ UNBOUNDED IS NOT "VERY LARGE", IT IS "NO CEILING TO HIT". Refusing on a machine that
         * has no limit would invent a failure the host never asked for. */
        if ($left === null) {
            return;
        }

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('conversion_source_undecodable');
        }

        $needed = (int) ($width * $height * self::BYTES_A_PIXEL * self::MARGIN);

        if ($needed >= $left) {
            throw new \RuntimeException('conversion_source_needs_more_memory');
        }
    }
}
