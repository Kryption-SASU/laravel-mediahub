<?php

declare(strict_types=1);

namespace Kryption\MediaHub\ValueObjects;

use Illuminate\Http\UploadedFile;

/**
 * WHAT IS BEING UPLOADED — whichever door it comes in through.
 *
 * ⚠️ FOUR ENTRANCES, ONE CONTINUATION. A file received from a form, read from a disk, taken
 * from a stream or downloaded from a URL then follows the same path: validation, quota, naming,
 * writing. Without this object, every door reinvents its own validation — and one of them ends
 * up forgetting it. That is what happened to the module this package replaces: its upload had
 * three entry points, one of which validated nothing.
 *
 * ⚠️ THE ORIGINAL NAME IS A STRING SUPPLIED BY THE CLIENT. It serves as a LABEL, never as a
 * file name: kept as it is, it writes wherever it likes.
 *
 * ⚠️ AND THE DECLARED TYPE IS ONLY A HINT. The one that counts is read from the CONTENT.
 */
final class UploadedPayload
{
    /**
     * @param  resource|null  $stream
     */
    private function __construct(
        public readonly string $originalName,
        public readonly ?string $declaredMimeType = null,
        public readonly ?int $size = null,
        public readonly ?string $localPath = null,
        public readonly ?string $sourceDisk = null,
        public readonly ?string $sourcePath = null,
        public readonly mixed $stream = null,
        public readonly ?string $sourceUrl = null,
    ) {
    }

    /** A file received from a form. */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        return new self(
            originalName: $file->getClientOriginalName(),
            declaredMimeType: $file->getClientMimeType(),
            size: $file->getSize() === false ? null : $file->getSize(),
            localPath: $file->getRealPath() === false ? null : $file->getRealPath(),
        );
    }

    /** A file already sitting on the local filesystem. */
    public static function fromLocalFile(string $path, ?string $originalName = null): self
    {
        return new self(
            originalName: $originalName ?? basename($path),
            size: is_file($path) ? (filesize($path) ?: null) : null,
            localPath: $path,
        );
    }

    /** A file already sitting on a Laravel disk. */
    public static function fromDisk(string $disk, string $path, ?string $originalName = null): self
    {
        return new self(
            originalName: $originalName ?? basename($path),
            sourceDisk: $disk,
            sourcePath: $path,
        );
    }

    /** A stream — a reassembled chunked upload, something generated on the fly. */
    public static function fromStream(mixed $stream, string $originalName): self
    {
        return new self(originalName: $originalName, stream: $stream);
    }

    /** A URL: the download happens later, outside the request. */
    public static function fromUrl(string $url, ?string $originalName = null): self
    {
        return new self(
            originalName: $originalName ?? basename((string) parse_url($url, PHP_URL_PATH)) ?: 'media',
            sourceUrl: $url,
        );
    }

    /**
     * ⚠️ INSPECTION REQUIRES A LOCAL FILE. Reading a MIME type from the content, measuring an
     * image before decoding it, computing a checksum: none of that is done on a promise. A
     * payload that does not yet have a local file must be materialised before being validated —
     * that is the caller's job, and it is explicit.
     */
    public function isInspectable(): bool
    {
        return $this->localPath !== null && is_file($this->localPath);
    }

    /** The same payload, once written locally. */
    public function withLocalPath(string $path): self
    {
        return new self(
            originalName: $this->originalName,
            declaredMimeType: $this->declaredMimeType,
            size: is_file($path) ? (filesize($path) ?: $this->size) : $this->size,
            localPath: $path,
            sourceDisk: $this->sourceDisk,
            sourcePath: $this->sourcePath,
            stream: $this->stream,
            sourceUrl: $this->sourceUrl,
        );
    }

    /** The extension as the CLIENT declares it — a hint, not a truth. */
    public function declaredExtension(): string
    {
        return strtolower((string) pathinfo($this->originalName, PATHINFO_EXTENSION));
    }
}
