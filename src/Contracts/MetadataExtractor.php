<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * WHAT THE FILE ITSELF TELLS US: dimensions, duration, camera data.
 *
 * ⚠️ NOTHING IT RETURNS COMES FROM THE CLIENT. The name, the declared type and the announced
 * dimensions are strings supplied by the caller: they serve as hints, never as truth.
 */
interface MetadataExtractor
{
    /** @return array<string, mixed> */
    public function extract(string $disk, string $path, string $mimeType): array;
}
