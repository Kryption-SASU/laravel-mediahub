<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\DiskResolver;

/**
 * A SINGLE DISK, the one from the configuration — unless the caller names another.
 *
 * ⚠️ `$context['disk']` IS HONOURED, AND THAT IS WHAT MAKES `onDisk()` REAL. A collection that
 * declares a disk and is then ignored by the default resolver is a setting that reads as a
 * promise and does nothing — worse than not offering it. Honouring it here means a host gets
 * per-collection disks without writing a resolver.
 *
 * ⚠️ AND THE VALUE COMES FROM HOST CODE, NEVER FROM A REQUEST. The context is assembled by the
 * package and by collection declarations; no controller in this package copies request input
 * into it. A host that starts doing so hands the choice of storage to its clients — which is
 * why this is said here rather than left to be discovered.
 */
final class SingleDiskResolver implements DiskResolver
{
    public function __construct(private readonly string $disk)
    {
    }

    public function forUpload(array $context): string
    {
        $named = $context['disk'] ?? null;

        return is_string($named) && $named !== '' ? $named : $this->disk;
    }
}
