<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Kryption\MediaHub\Exceptions\StorageMisconfigured;

/**
 * WHERE THE BYTES GO — a disk already configured, or a path given by hand.
 *
 * ⚠️ AND IN BOTH CASES, IT IS A DISK. That is this class's design decision, and it deserves
 * defending: a path supplied by the host is TURNED INTO a local disk declared on the fly,
 * rather than handled by a second branch throughout the package. Otherwise every read, every
 * write, every existence check, every stream and every signed URL would have two
 * implementations — and the second, less travelled, would be the one that rots. The module this
 * package replaces did exactly that: it juggled `Storage::disk()` and `public_path()`, the two
 * diverged when it moved to the cloud, and its download has been broken ever since without
 * anyone noticing.
 *
 * ⚠️ `disk` MODE COVERS EVERY DISK THE HOST HAS, including the ones it adds later. The package
 * names none of them in its code — that was the first anomaly found in the original, which
 * wrote `Storage::disk('ovh')` in four places.
 *
 * ⚠️ A PATH INSIDE THE WEB ROOT IS REFUSED, AND THAT IS NOT NEGOTIABLE. The front-end server
 * serves files from there directly, without going through PHP: the scope, the access policy and
 * the signed URLs then become purely decorative — a guessed identifier is enough. That is
 * precisely the mistake the original made with its archives.
 */
final class StorageDisk
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * The name of the disk to use — declaring it first, if the host gave a path.
     *
     * @param  string  $publicPath  the application's web root, so it can be refused
     */
    public function resolve(string $publicPath): string
    {
        $driver = strtolower((string) $this->config->get('mediahub.storage.driver', 'disk'));

        return match ($driver) {
            'disk' => $this->named(),
            'path' => $this->declared($publicPath),
            /*
             * ⚠️ NO FALLBACK. Falling back to the default disk would send the originals
             * somewhere other than where the host believes it writes them, and it would find
             * out while looking for why its backup is empty.
             */
            default => throw StorageMisconfigured::because('unknown_storage_driver: '.$driver),
        };
    }

    private function named(): string
    {
        $name = (string) $this->config->get('mediahub.storage.disk', '');

        if ($name === '') {
            throw StorageMisconfigured::because('storage_disk_missing');
        }

        /*
         * ⚠️ WE DO NOT CHECK THAT THE DISK EXISTS, AND THAT IS A CHECK THAT WAS REMOVED. The
         * intent was good — catch a typo at boot rather than on the first upload. But a disk is
         * not always declared in `filesystems.disks`: `Storage::fake()`, `Storage::set()` and
         * `Storage::extend()` register it on the manager WITHOUT touching the configuration.
         * The check therefore refused perfectly valid setups — measured: it brought down five
         * bench classes at once.
         *
         * A wrongful refusal at boot is worse than a late error: Laravel's names the disk and
         * can be understood, whereas a refusal to boot offers no way around it.
         */
        return $name;
    }

    private function declared(string $publicPath): string
    {
        $path = $this->config->get('mediahub.storage.path');

        if (! is_string($path) || trim($path) === '') {
            throw StorageMisconfigured::because('storage_path_missing');
        }

        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        $this->refuseDubiousPath($path, rtrim(str_replace('\\', '/', $publicPath), '/'));

        $name = (string) $this->config->get('mediahub.storage.name', 'mediahub');

        $this->config->set('filesystems.disks.'.$name, [
            'driver' => 'local',
            'root' => $path,

            /*
             * ⚠️ PRIVATE, AND `serve` FALSE. The path is outside the web root: it is the
             * package's route that serves the bytes, applying the scope and the policy. Letting
             * Laravel build its own serving URLs would short-circuit both.
             */
            'visibility' => 'private',
            'serve' => false,

            /*
             * ⚠️ `throw` FALSE: the package treats a missing object as a fact, not a failure —
             * an archive records it, a thumbnail is not built. Raising would bring down the
             * whole request over one missing file in a thousand.
             */
            'throw' => false,

            /*
             * A public URL only makes sense if the host has exposed that folder itself — via a
             * symlink, another domain, a cache in front. That is its choice, and it writes it;
             * without it, the package serves through its own route.
             */
            'url' => $this->config->get('mediahub.storage.url'),
        ]);

        return $name;
    }

    private function refuseDubiousPath(string $path, string $webRoot): void
    {
        /*
         * ⚠️ A RELATIVE PATH DOES NOT NAME THE SAME FOLDER DEPENDING ON WHO RUNS IT. The web
         * server, a scheduler command and a queue worker do not share a current directory: the
         * media would scatter across three places, and two of them would be invisible.
         */
        if (! str_starts_with($path, '/') && ! preg_match('#^[A-Za-z]:/#', $path)) {
            throw StorageMisconfigured::because('storage_path_not_absolute: '.$path);
        }

        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw StorageMisconfigured::because('storage_path_traversal: '.$path);
        }

        if ($webRoot !== '' && ($path === $webRoot || str_starts_with($path.'/', $webRoot.'/'))) {
            throw StorageMisconfigured::because('storage_path_inside_public: '.$path);
        }
    }
}
