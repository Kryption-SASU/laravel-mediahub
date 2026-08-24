<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Events;

use Kryption\MediaHub\Models\Media;

final class MediaMoved
{
    public function __construct(public readonly Media $media)
    {
    }
}
