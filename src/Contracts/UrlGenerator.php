<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use DateTimeInterface;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;

/**
 * THE URL OF A MEDIA — computed, never stored.
 *
 * ⚠️ IT TAKES THE MODEL, NOT A DISK AND A PATH. The first version of this contract took
 * `(disk, path, ?int id)`: it asked for a DATABASE identifier while the exposed key is a
 * `uuid`, precisely so that a sequential integer would not travel in a URL. Two of the
 * package's rules contradicted each other in its signature — and that is the kind of
 * contradiction that gets resolved, in practice, on the wrong side.
 *
 * ⚠️ A URL SERVED TO A BROWSER IS SIGNED AND EXPIRING by default.
 *
 * ⚠️ AND THE FALLBACK MUST BE LOUD. Silently falling back to the public URL serves permanent
 * links with no message saying so — that is, a leak that looks like a feature.
 */
interface UrlGenerator
{
    /** The original, meant to be displayed inline. */
    public function url(Media $media): string;

    /** A derivative — thumbnail, preview — also meant for display. */
    public function conversionUrl(MediaConversion $conversion): string;

    /**
     * The download, as an attachment and under the displayed name.
     *
     * ⚠️ THIS ONE CANNOT GO THROUGH THE STORAGE. A file name and an attachment header cannot
     * be set portably on a pre-signed URL: that is an HTTP response, not an object.
     */
    public function downloadUrl(Media $media): string;

    /** The same as `url()`, but with an expiry chosen by the caller. */
    public function temporaryUrl(Media $media, DateTimeInterface $until): string;
}
