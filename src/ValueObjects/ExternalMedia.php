<?php

declare(strict_types=1);

namespace Kryption\MediaHub\ValueObjects;

/**
 * A MEDIA HOSTED ELSEWHERE.
 *
 * ⚠️ ITS PREVIEW IS AN EXTERNAL URL, and it is the only family whose preview is not signed: it
 * names no object on our storage.
 */
final class ExternalMedia
{
    public function __construct(
        public readonly string $provider,
        public readonly string $url,
        public readonly string $title,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?int $duration = null,
    ) {
    }
}
