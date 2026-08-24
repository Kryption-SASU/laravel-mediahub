<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Events;

use Kryption\MediaHub\Models\Media;

final class MediaUploaded
{
    /**
     * @param  bool  $reused  the content already existed: no byte was written
     */
    public function __construct(public readonly Media $media, public readonly bool $reused = false)
    {
    }
}
