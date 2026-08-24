<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\MediaTypeResolver;
use Kryption\MediaHub\Enums\MediaType;
use Kryption\MediaHub\Support\ExtensionFamilies;

/**
 * THE NATURE IS READ FROM THE MIME TYPE, and from nothing else.
 *
 * ⚠️ WE LOOK AT THE FAMILY BEFORE THE EXACT TYPE: `image/*` covers formats that do not exist
 * yet, and an exhaustive list is a list that ages.
 */
final class MimeMediaTypeResolver implements MediaTypeResolver
{
    /** @var array<string, MediaType> */
    /**
     * ⚠️ PUBLIC BECAUSE AN SQL FILTER MUST BE ABLE TO REPLAY IT. On a schema without a family
     * column, the reconstruction happens in a `WHERE`: without access to this list it would
     * classify differently from the resolver, and the two would contradict each other on screen.
     */
    public const OFFICE_PREFIX = 'application/vnd.openxmlformats-officedocument.';

    public const DOCUMENTS = [
        'application/pdf' => MediaType::Document,
        'text/plain' => MediaType::Document,
        'text/csv' => MediaType::Document,
        'application/zip' => MediaType::Document,
        'application/msword' => MediaType::Document,
        'application/vnd.ms-excel' => MediaType::Document,
        'application/vnd.ms-powerpoint' => MediaType::Document,
    ];

    public function resolve(string $mimeType, ?string $extension = null): MediaType
    {
        $mime = strtolower(trim($mimeType));

        if (str_starts_with($mime, 'image/')) {
            return MediaType::Image;
        }

        if ($this->isAmbiguousContainer($mime) && $this->extensionSaysAudio($extension)) {
            return MediaType::Audio;
        }

        if (str_starts_with($mime, 'video/')) {
            return MediaType::Video;
        }

        if (str_starts_with($mime, 'audio/')) {
            return MediaType::Audio;
        }

        /*
         * ⚠️ WHEN SNIFFING HAS NO OPINION, THE EXTENSION DECIDES. Without this, a container
         * the running PHP build cannot recognise is filed as "other": stored and served
         * correctly, but absent from the video filter — a difference visible on screen that
         * depends on nothing but the runtime.
         */
        if ($mime === ExtensionFamilies::NO_OPINION && $extension !== null) {
            $family = ExtensionFamilies::primary($extension);

            if ($family !== null) {
                return MediaType::from($family);
            }
        }

        if (isset(self::DOCUMENTS[$mime])) {
            return self::DOCUMENTS[$mime];
        }

        if (str_starts_with($mime, self::OFFICE_PREFIX)) {
            return MediaType::Document;
        }

        return MediaType::Other;
    }

    /**
     * ⚠️ THREE TYPES NAME A CONTAINER, NOT A NATURE. ASF carries sound and moving pictures
     * alike — a `.wma` and a `.wmv` are the same format — and Ogg does the same. `finfo`
     * therefore answers `video/…` for a purely audio file, and without a tie-breaker every WMA
     * would be filed among the videos.
     */
    private function isAmbiguousContainer(string $mime): bool
    {
        return in_array($mime, ['video/x-ms-asf', 'video/ogg', 'application/ogg'], true);
    }

    /**
     * ⚠️ THE EXTENSION BREAKS THE TIE, IT DOES NOT DECIDE. It comes from the client: letting it
     * rule alone would reopen the door validation has just closed. Here it only steps in
     * between TWO natures the content itself does not distinguish, and the worst it can cause
     * is a wrong display category — never a badly served file.
     */
    private function extensionSaysAudio(?string $extension): bool
    {
        return in_array(strtolower(trim((string) $extension)), ['wma', 'oga', 'ogg'], true);
    }
}
