<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Backends;

use Kryption\MediaHub\Models\Media;

/**
 * THE REFLECTION OF A DERIVATIVE INSIDE THE HOST'S PROPERTY BLOCK.
 *
 * ⚠️ IT EXISTS FOR COHABITATION, AND FOR THAT ALONE. While the old module is running, IT is
 * what displays the thumbnails, and it can only read them in one place:
 * `media_files.options['thumb']`, a relative path on the media's disk — verified in its
 * `getThumbnailUrlAttribute()` accessor. A derivative built by the new package and recorded only
 * in its own table would therefore be invisible everywhere the user looks today.
 *
 * ⚠️ THE BLOCK IS A REFLECTION, NEVER A SOURCE. What counts is the conversions table: it alone
 * carries the state, the error, and therefore the ability to regenerate a single thumbnail —
 * everything the original's JSON block could not carry, and which made its thumbnails
 * irreparable. Nothing is READ from the block; we only write to it for the old screen.
 *
 * ⚠️ AND IT HAS A HAPPY SIDE EFFECT WORTH KNOWING: when a media is deleted, the old module
 * removes the files listed in `options` for each size in `config('media.sizes')`. Our
 * thumbnails being listed there, they are cleaned up by it — which compensates for the absence
 * of a foreign key between its table and ours.
 *
 * ⚠️ IT WITHDRAWS IN ONE LINE. Once the old module is gone, the list becomes empty and nothing
 * is written to the block any more. That is what makes this class a transitional measure and
 * not a debt: it has an expiry date and a switch.
 */
final class LegacyConversionMirror
{
    /**
     * ⚠️ THE LIST IS READ THROUGH `HostSchema`, NOT FROM THE CONFIGURATION DIRECTLY. A first
     * version read `config('mediahub.backend.conversion_mirror')`: it therefore never saw the
     * PRESET, and the mirror believed itself disabled on the only schema it exists for. Nothing
     * raised — it simply mirrored nothing, and the old screen would have stayed empty without
     * anyone knowing why.
     *
     * @return array<int, string>
     */
    public function mirrored(): array
    {
        return array_values((array) HostSchema::setting('conversion_mirror'));
    }

    public function reflects(string $conversion): bool
    {
        return in_array($conversion, $this->mirrored(), true);
    }

    /**
     * ⚠️ WE WRITE THE RELATIVE PATH, NOT A URL. The old accessor builds the URL itself from the
     * media's disk; putting a full URL there would make it treat the value as an external link,
     * and the day the storage changes, it would be wrong everywhere and forever.
     */
    public function reflect(Media $media, string $conversion, string $path): void
    {
        if (! $this->reflects($conversion)) {
            return;
        }

        $properties = (array) ($media->custom_properties ?? []);
        $properties[$conversion] = $path;

        $media->custom_properties = $properties;
        $media->save();
    }

    /** The reflection disappears when the derivative can no longer be served. */
    public function forget(Media $media, string $conversion): void
    {
        if (! $this->reflects($conversion)) {
            return;
        }

        $properties = (array) ($media->custom_properties ?? []);

        if (! array_key_exists($conversion, $properties)) {
            return;
        }

        unset($properties[$conversion]);

        $media->custom_properties = $properties;
        $media->save();
    }
}
