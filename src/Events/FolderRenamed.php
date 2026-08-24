<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Events;

use Kryption\MediaHub\Models\MediaFolder;

final class FolderRenamed
{
    public function __construct(public readonly MediaFolder $folder)
    {
    }
}
