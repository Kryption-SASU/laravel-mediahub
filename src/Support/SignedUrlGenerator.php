<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Routing\UrlGenerator as Router;
use Illuminate\Support\Carbon;
use Kryption\MediaHub\Contracts\UrlGenerator;
use Kryption\MediaHub\Exceptions\UrlSigningFailed;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;

/**
 * THE URL, SIGNED AND EXPIRING BY DEFAULT.
 *
 * ⚠️ TWO PATHS, AND THE FIRST DOES NOT GO THROUGH PHP. When the storage can sign — S3, Swift,
 * any remote object store — the browser fetches the file straight from it. That is the only way
 * to serve video without tying up one application process per viewer. When it cannot — a local
 * disk — we go back through our own route, signed as well.
 *
 * ⚠️ AND WE ADD NOTHING TO A URL SIGNED BY THE STORAGE. The temptation is strong: hang the
 * media identifier on it so the client can ask for a fresh one. But a signature covers the
 * ENTIRE query string: the smallest added parameter invalidates it, and the error only shows at
 * runtime, at the provider. The identifier therefore travels in the JSON resource, never in the
 * URL.
 *
 * ⚠️ THERE IS NO "SIGNED BUT WITHOUT AN EXPIRY". A zero or negative duration is brought back to
 * one minute: an eternal signed link combines the drawbacks of both worlds — it does not
 * expire, and it gives the impression that it does. For a permanent link, `signed` is set to
 * false, and that is written down somewhere.
 *
 * ⚠️ THE FALLBACK IS LOUD. If signing is requested and neither path is available, we raise.
 * Handing back the storage's public URL would make the screen work and distribute permanent
 * links to private files.
 */
final class SignedUrlGenerator implements UrlGenerator
{
    /**
     * THE NAME OF THE ROUTE PARAMETER CARRYING THE MEDIA.
     *
     * ⚠️ IT IS FIXED, AND THAT IS A CORRECTION. The configuration used to offer a tunable
     * `renewal_parameter`, so the client could read the identifier back out of the URL and ask
     * for a fresh one. Two things made it untenable: Laravel's implicit binding matches the
     * route parameter to the NAME of the controller argument, which a configuration file cannot
     * rename; and on the path that matters — the storage's pre-signed URL — no parameter can be
     * added without breaking the signature. The identifier therefore travels in the JSON
     * resource, where it is always present.
     */
    private const PARAMETER = 'media';

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly Config $config,
        private readonly Router $router,
    ) {
    }

    public function url(Media $media): string
    {
        return $this->serve($media, (string) $media->disk, (string) $media->path, $this->expiry());
    }

    public function temporaryUrl(Media $media, DateTimeInterface $until): string
    {
        return $this->serve($media, (string) $media->disk, (string) $media->path, $until);
    }

    public function conversionUrl(MediaConversion $conversion): string
    {
        $media = $conversion->media;

        if ($media === null) {
            throw UrlSigningFailed::because('conversion_without_media');
        }

        if ($this->signing()) {
            $direct = $this->fromStorage((string) $conversion->disk, (string) $conversion->path, $this->expiry());

            if ($direct !== null) {
                return $direct;
            }
        } else {
            return $this->public((string) $conversion->disk, (string) $conversion->path);
        }

        return $this->route('conversion', [
            self::PARAMETER => $media->getRouteKey(),
            'conversion' => (string) $conversion->name,
        ], $this->expiry());
    }

    /**
     * ⚠️ DOWNLOADING NEVER GOES THROUGH THE STORAGE. The displayed name and the attachment
     * header are an HTTP response, not a property of the object — and setting them on a
     * pre-signed URL depends on the provider, when it is possible at all.
     */
    public function downloadUrl(Media $media): string
    {
        return $this->route('download', [self::PARAMETER => $media->getRouteKey()], $this->expiry());
    }

    private function serve(Media $media, string $disk, string $path, DateTimeInterface $until): string
    {
        if (! $this->signing()) {
            return $this->public($disk, $path);
        }

        $direct = $this->fromStorage($disk, $path, $until);

        if ($direct !== null) {
            return $direct;
        }

        return $this->route('file', [self::PARAMETER => $media->getRouteKey()], $until);
    }

    /**
     * ⚠️ "CAN THE DISK SIGN" IS ASKED OF THE DISK, not of a hand-maintained list of driver
     * names. A local disk can be configured to sign, an S3 one may not be; the question only
     * has an answer at runtime.
     */
    private function fromStorage(string $disk, string $path, DateTimeInterface $until): ?string
    {
        $storage = $this->filesystems->disk($disk);

        if (! method_exists($storage, 'providesTemporaryUrls') || ! $storage->providesTemporaryUrls()) {
            return null;
        }

        try {
            return $storage->temporaryUrl($path, $until);
        } catch (\Throwable $e) {
            throw UrlSigningFailed::because('storage_signing_failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function route(string $name, array $parameters, DateTimeInterface $until): string
    {
        $route = $this->routeName($name);

        try {
            return $this->signing()
                ? $this->router->temporarySignedRoute($route, $until, $parameters)
                : $this->router->route($route, $parameters);
        } catch (\Throwable $e) {
            /*
             * ⚠️ THE MOST LIKELY CAUSE IS THAT THE ROUTES ARE DISABLED. A host that turns
             * `routes.enabled` off on a disk unable to sign has no way left to serve its files:
             * it needs to learn that here, and not from a broken image in production.
             */
            throw UrlSigningFailed::because('route_unavailable: '.$route);
        }
    }

    private function public(string $disk, string $path): string
    {
        return $this->filesystems->disk($disk)->url($path);
    }

    private function signing(): bool
    {
        return (bool) $this->config->get('mediahub.urls.signed', true);
    }

    private function expiry(): DateTimeInterface
    {
        $minutes = (int) $this->config->get('mediahub.urls.ttl', 60);

        return Carbon::now()->addMinutes(max(1, $minutes));
    }

    private function routeName(string $name): string
    {
        return (string) $this->config->get('mediahub.routes.as', 'mediahub.').$name;
    }
}
