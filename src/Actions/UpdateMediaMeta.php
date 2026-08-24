<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Kryption\MediaHub\Events\MediaMetaUpdated;
use Kryption\MediaHub\Models\Media;

/**
 * A MEDIA'S FREE-FORM PROPERTIES — alt text, caption, whatever the host wants to put there.
 *
 * ⚠️ THEY ARE MERGED, NOT REPLACED. A screen that only edits the alt text sends only that;
 * replacing the whole block would erase the caption entered somewhere else, with no error
 * reporting it. The original module overwrote, and that is how fields disappeared "on their
 * own".
 *
 * ⚠️ A `null` VALUE ERASES, AND IT IS THE ONLY WAY. Without it, a property set once could never
 * be removed again — the merge would carry it forward indefinitely.
 *
 * ⚠️ AND NOTHING HERE IS INTERPRETED BY THE PACKAGE. This block belongs to the host: giving it
 * a meaning here means deciding in its place, and being condemned to follow it.
 */
final class UpdateMediaMeta
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __invoke(Media $media, array $properties): Media
    {
        $current = (array) ($media->custom_properties ?? []);

        foreach ($properties as $key => $value) {
            if ($value === null) {
                unset($current[$key]);

                continue;
            }

            $current[$key] = $value;
        }

        $media->custom_properties = $current;
        $media->save();

        $this->events->dispatch(new MediaMetaUpdated($media));

        return $media;
    }
}
