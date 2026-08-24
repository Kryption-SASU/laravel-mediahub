<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Enums;

/**
 * THE NATURE OF A MEDIA — derived from the MIME type, never typed in.
 *
 * ⚠️ `Other` EXISTS SO THAT NOTHING RAISES. An unknown type is a common fact, not an error:
 * refusing to classify a file would amount to refusing to serve it.
 */
enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case External = 'external';
    case Other = 'other';
}
