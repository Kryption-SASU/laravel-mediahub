<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Kryption\MediaHub\Events\MediaMoved;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * MOVING A MEDIA FROM ONE FOLDER TO ANOTHER.
 *
 * ⚠️ NO BYTE MOVES, AND THAT IS THE POINT. The tree the user sees and the filing of objects on
 * the storage are two independent things: the path was decided on write and then recorded.
 * Making the bytes follow would turn a move — instant, reversible, riskless — into a copy
 * followed by a deletion, on remote storage, with no transaction to cover them.
 *
 * ⚠️ THE TARGET FOLDER IS AN ALREADY-RESOLVED MODEL, therefore already through the global
 * scope. Without that, moving would be the simplest way to bring a foreign file into your own
 * library.
 */
final class MoveMedia
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    public function __invoke(Media $media, ?MediaFolder $folder): Media
    {
        $media->folder_id = $folder?->getKey();
        $media->save();

        $this->events->dispatch(new MediaMoved($media));

        return $media;
    }
}
