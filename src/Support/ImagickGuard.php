<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * THE BOUNDS IMPOSED ON IMAGEMAGICK — and what they actually bound.
 *
 * ⚠️ NOT EVERY LIMIT REFUSES. `MEMORY`, `MAP` and `AREA` only decide WHERE the pixels are
 * cached: once exceeded, ImageMagick spills to disk and carries on. Measured on the bench: a
 * 4000×4000 image goes through without a murmur under a one-kilobyte memory limit. Trusting
 * those three means trusting a protection that does not exist.
 *
 * ⚠️ IT IS `WIDTH` AND `HEIGHT` THAT REFUSE, before any allocation. That is the only barrier
 * stopping a decompression bomb — an image of a few kilobytes that claims several gigabytes
 * once expanded.
 *
 * ⚠️ AND THESE LIMITS ARE PROCESS-WIDE, AND STICKY. `setResourceLimit()` does not apply to the
 * instance calling it but to the whole of ImageMagick, and the value outlives the instance. Two
 * consequences: they are set again BEFORE EVERY operation rather than trusting whatever is left
 * over, and the package changes that state for the entire host — which is accepted, since a
 * protection that only held for our own calls would not be one.
 */
final class ImagickGuard
{
    /**
     * THE BOUNDS OF THE CAPABILITY PROBE — fixed, and deliberately not the host's.
     *
     * ⚠️ THE PROBE BUILDS ITS OWN 8×8: there is nothing to protect against there, and bounding
     * it with the host's values would make it fail as soon as a tight setting is in force — the
     * package would then conclude "this format is unavailable" on a machine that reads it
     * perfectly well. The probe must measure the delegate, not the configuration.
     *
     * @var array<string, int>
     */
    public const PROBE = [
        'max_side' => 1024,
        'memory_mb' => 64,
        'map_mb' => 128,
        'disk_mb' => 64,
        'seconds' => 10,
        'threads' => 1,
    ];

    public static function available(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * @return array<string, int>
     */
    public static function limits(Config $config): array
    {
        $configured = (array) $config->get('mediahub.images.limits', []);

        return [
            'max_side' => max(1, (int) ($configured['max_side'] ?? 20000)),
            'memory_mb' => max(1, (int) ($configured['memory_mb'] ?? 256)),
            'map_mb' => max(1, (int) ($configured['map_mb'] ?? 512)),
            'disk_mb' => max(0, (int) ($configured['disk_mb'] ?? 1024)),
            'seconds' => max(0, (int) ($configured['seconds'] ?? 30)),
            'threads' => max(1, (int) ($configured['threads'] ?? 1)),
        ];
    }

    /**
     * @param  array<string, int>  $limits
     */
    public static function bound(\Imagick $image, array $limits): void
    {
        $mb = static fn (int $n): int => $n * 1024 * 1024;

        /*
         * ⚠️ THE ONLY BARRIER THAT REFUSES, AND IT ACTS BEFORE ALLOCATION. The others move the
         * problem to the disk; this one stops it.
         */
        self::set($image, 'RESOURCETYPE_WIDTH', $limits['max_side']);
        self::set($image, 'RESOURCETYPE_HEIGHT', $limits['max_side']);

        self::set($image, 'RESOURCETYPE_MEMORY', $mb($limits['memory_mb']));
        self::set($image, 'RESOURCETYPE_MAP', $mb($limits['map_mb']));
        self::set($image, 'RESOURCETYPE_AREA', $mb($limits['memory_mb']));
        self::set($image, 'RESOURCETYPE_DISK', $mb($limits['disk_mb']));

        /*
         * ⚠️ ONE THREAD BY DEFAULT. A thumbnail has nothing to gain from parallelism, and a
         * job queue starting several at once would multiply consumption by the number of cores
         * without anyone having asked for it.
         */
        self::set($image, 'RESOURCETYPE_THREAD', $limits['threads']);

        /* ⚠️ ZERO MEANS "NO LIMIT" IN IMAGEMAGICK, and that is what ImageMagick itself ships with. */
        self::set($image, 'RESOURCETYPE_TIME', $limits['seconds']);
    }

    /**
     * HOW MANY PIXELS, WITHOUT DECODING THE IMAGE.
     *
     * ⚠️ `pingImage()` READS THE HEADER ONLY. That is what makes it possible to measure a
     * format `getimagesize()` ignores — iPhone HEIC in particular — without doing exactly what
     * the measurement exists to prevent: expanding the image in memory to find out whether it
     * is too big.
     *
     * ⚠️ AND THE PROBE IS BOUNDED TOO. A header is hostile data like any other.
     *
     * @param  array<string, int>  $limits
     * @return int|null the pixel count, or null if NOTHING here can open this file
     */
    public static function pixels(string $path, array $limits): ?int
    {
        if (! self::available()) {
            return null;
        }

        try {
            $probe = new \Imagick();
            self::bound($probe, $limits);
            $probe->pingImage($path);

            $pixels = $probe->getImageWidth() * $probe->getImageHeight();
            $probe->clear();

            return $pixels > 0 ? $pixels : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ⚠️ NOT EVERY CONSTANT EXISTS EVERYWHERE. `RESOURCETYPE_WIDTH` and `RESOURCETYPE_TIME`
     * arrived after the others: naming them directly would make the package fail on an older
     * extension, for a bound we can simply leave unset.
     */
    private static function set(\Imagick $image, string $constant, int $value): void
    {
        $name = '\\Imagick::'.$constant;

        if (! defined($name)) {
            return;
        }

        try {
            $image->setResourceLimit((int) constant($name), $value);
        } catch (\Throwable) {
            /* A bound ImageMagick refuses to set must not stop the others. */
        }
    }
}
