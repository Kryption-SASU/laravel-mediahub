<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Kryption\MediaHub\Enums\MediaType;

/**
 * IMAGE, VIDEO, AUDIO, DOCUMENT — deduced from the MIME type, never typed in.
 *
 * ⚠️ THE TYPE IS DERIVED, NOT STORED AS AN INDEPENDENT TRUTH. Two sources for the same piece of
 * information always end up diverging.
 */
interface MediaTypeResolver
{
    public function resolve(string $mimeType, ?string $extension = null): MediaType;
}
