<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use php_user_filter;

/**
 * COUNTING THE BYTES THAT GO PAST, WITHOUT STANDING IN THE WAY OF THEM.
 *
 * ⚠️ COUNTED PER FILE WOULD NOT BE A PROGRESS BAR. An archive is often one large video and four
 * small images: reporting after each entry leaves the bar at 3% for two minutes and then at
 * 100%, which is a worse lie than no bar at all. The count has to happen inside the reading of a
 * file, and a stream filter is where PHP lets that be done without owning the read loop.
 *
 * ⚠️ AND WITHOUT OWNING THE READ LOOP IS THE POINT. ZipStream reads the handle itself, in
 * blocks of its choosing; wrapping it in something that read ahead would either buffer the file
 * — the one thing streaming exists to avoid — or quietly change how much is read at a time.
 * A filter sees every block and passes each one straight through.
 *
 * ⚠️ THE COUNTER ARRIVES THROUGH `$params`, NOT THROUGH A STATIC. `stream_filter_append` hands
 * its fourth argument to the instance, which is the only way to give one filter a destination
 * without a global — and a global would be shared by every archive being streamed at once.
 */
final class CountingStreamFilter extends php_user_filter
{
    public const NAME = 'mediahub.archive.progress';

    /** ⚠️ REGISTERED ONCE PER PROCESS: a second registration under the same name fails. */
    public static function register(): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            if ($this->params instanceof \Closure) {
                ($this->params)($bucket->datalen);
            }

            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
