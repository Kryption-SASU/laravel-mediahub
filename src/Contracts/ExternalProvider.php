<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Kryption\MediaHub\ValueObjects\ExternalMedia;

/**
 * A MEDIA THAT IS NOT OURS — a video hosted elsewhere.
 *
 * ⚠️ ITS PREVIEW IS AN EXTERNAL URL BY NATURE, and it is the only family whose preview is not
 * signed: it names no object on our storage.
 */
interface ExternalProvider
{
    public function matches(string $url): bool;

    public function resolve(string $url): ?ExternalMedia;
}
