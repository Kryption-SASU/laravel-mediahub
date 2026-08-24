<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Events;

use Illuminate\Support\Collection;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * Items have gone to the trash — their bytes, however, are still there.
 *
 * ⚠️ ONE EVENT FOR THE WHOLE BATCH, NOT ONE PER ITEM. The batch is atomic: it passes whole or
 * not at all. Emitting one per item would suggest the opposite to the listener, and would
 * invite it to react to states that never existed separately.
 */
final class ItemsTrashed
{
    /**
     * @param  Collection<int, Media>  $media
     * @param  Collection<int, MediaFolder>  $folders
     */
    public function __construct(public readonly Collection $media, public readonly Collection $folders)
    {
    }
}
