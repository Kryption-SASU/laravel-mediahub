<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Contracts\ConversionDrivers;

/**
 * SEVERAL DRIVERS, ASKED IN ORDER — the first that can, does.
 *
 * ⚠️ THE ORDER IS THE PRIORITY, AND IT IS NOT ARBITRARY. The image driver is asked first because
 * it is the one a host configures, and because ffmpeg would otherwise take work that is not its
 * own: it reads still images perfectly well, and would quietly become the image library nobody
 * chose — with the host's `mediahub.images.driver` setting doing nothing at all.
 *
 * ⚠️ AND EVERY DRIVER IS ASKED FRESH, NEVER REMEMBERED. `supports()` depends on the machine as
 * much as on the type: the same application answers differently on a web server that has ffmpeg
 * and a queue worker that does not, and a choice cached from one would raise on the other
 * instead of declining.
 */
final class DriverChain implements ConversionDrivers
{
    /** @var array<int, ConversionDriver> */
    private readonly array $drivers;

    public function __construct(ConversionDriver ...$drivers)
    {
        $this->drivers = $drivers;
    }

    public function for(string $mimeType): ?ConversionDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($mimeType)) {
                return $driver;
            }
        }

        return null;
    }
}
