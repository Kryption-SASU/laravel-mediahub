<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Events;

use Kryption\MediaHub\Models\Media;

/**
 * ⚠️ IT CARRIES BOTH. A listener holding only the copy would not know what it is a copy of —
 * and that is precisely what an audit log has to record.
 */
final class MediaCopied
{
    public function __construct(public readonly Media $source, public readonly Media $copy)
    {
    }
}
