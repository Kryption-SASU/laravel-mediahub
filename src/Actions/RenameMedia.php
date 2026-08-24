<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Kryption\MediaHub\Events\MediaRenamed;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\Media;

/**
 * RENAMING A MEDIA — the DISPLAYED name, and only that.
 *
 * ⚠️ TWO NAMES COEXIST, AND CONFUSING THEM IS A MISTAKE. `name` is what the person sees and
 * changes; `file_name` is what exists on the storage. Renaming the second on every edit would
 * make an object's location depend on a human keystroke — and every rename would become a copy
 * followed by a deletion, with its window for breakage and its dead link in everything that
 * pointed at it.
 *
 * ⚠️ AND THAT IS WHY THIS ACTION TOUCHES NEITHER `path`, NOR `file_name`, NOR THE EXTENSION. A
 * media renamed "report.txt" is still a PDF: the type is read from the content, not from a
 * typed string.
 */
final class RenameMedia
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    public function __invoke(Media $media, string $name): Media
    {
        $name = trim($name);

        if ($name === '') {
            throw OperationRejected::because('media_name_required');
        }

        $media->name = $name;
        $media->save();

        $this->events->dispatch(new MediaRenamed($media));

        return $media;
    }
}
