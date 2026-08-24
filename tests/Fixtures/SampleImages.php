<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Fixtures;

/**
 * REAL BYTES, TO EXERCISE A PROMISE.
 *
 * ⚠️ THESE SAMPLES ARE FROZEN, NOT BUILT ON THE FLY. Building them with GD or Imagick would
 * make the suite circular: a WebP could only be produced where GD already reads WebP, which is
 * precisely where the test teaches nothing. Frozen, they make it possible to present a WebP to a
 * GD that cannot read it — the only case that matters.
 *
 * ⚠️ THEY ARE TINY AND HOLD NO SECRET: 6×4 pixels of a single colour, produced once and then
 * transcribed here. The PDF is written by hand, and its validity was checked with Ghostscript —
 * without which a failed probe could have come from the bytes rather than from the host.
 */
final class SampleImages
{
    /** @var array<string, string> real type → sample key */
    public const BY_TYPE = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/tiff' => 'tiff',
        'application/pdf' => 'pdf',
    ];

    private const B64 = [
        'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAYAAAAECAIAAAAiZtkUAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWPkqjjBgAqYGDAAcUIAYCIBUpqbu/MAAAAASUVORK5CYII=',
        'jpeg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgABAAGAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5Siiiv0M/OD/2Q==',
        'gif' => 'R0lGODdhBgAEAIAAAAx6zAAAACwAAAAABgAEAAACBISPqVcAOw==',
        'webp' => 'UklGRjwAAABXRUJQVlA4IDAAAAAQAgCdASoGAAQAAUAmJaACdLoB+AH4AAPIAP7u/5/+oLfJnnz2//r0QeIN+dkAAAA=',
        'tiff' => 'SUkqAFAAAAAKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgKeMgPAAABAwABAAAABgAAAAEBAwABAAAABAAAAAIBAwADAAAACgEAAAMBAwABAAAAAQAAAAYBAwABAAAAAgAAAAoBAwABAAAAAQAAABEBBAABAAAACAAAABIBAwABAAAAAQAAABUBAwABAAAAAwAAABYBAwABAAAABAAAABcBBAABAAAASAAAABwBAwABAAAAAQAAACkBAwACAAAAAAABAD4BBQACAAAAQAEAAD8BBQAGAAAAEAEAAAAAAAAIAAgACACF61EAAACAAMP1qAAAAAACzcxMAAAAAAHNzEwAAACAAM3MTAAAAAACj8L1AAAAABA3GqAAAAAAAiuHCgAAACAA',
    ];

    public static function bytes(string $mimeType): string
    {
        $key = self::BY_TYPE[strtolower($mimeType)] ?? null;

        if ($key === null) {
            throw new \InvalidArgumentException("No sample for {$mimeType}.");
        }

        if ($key === 'pdf') {
            return self::pdf();
        }

        return (string) base64_decode(self::B64[$key], true);
    }

    /**
     * ⚠️ WRITTEN IN PLAIN TEXT RATHER THAN BASE64: a minimal PDF can be read back, and its
     * readability is exactly what the Imagick driver's probe puts to the test.
     */
    private static function pdf(): string
    {
        return "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 8 8]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n"
            ."%%EOF\n";
    }
}
