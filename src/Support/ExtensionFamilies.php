<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

/**
 * Which media families an extension may legitimately carry.
 *
 * ⚠️ A SET, NOT A SINGLE FAMILY. An `.wma` file is an ASF container — the very same container
 * as `.wmv` — so content sniffing answers `video/x-ms-asf` for a purely audio file. A rule
 * saying "audio extension implies audio type" therefore rejects every WMA, with a message
 * nobody can act on. Ogg raises the mirror problem: an `.ogg` may carry video.
 *
 * ⚠️ NO SET CONTAINS `text` OR `application`, AND THAT IS WHERE THE CHECK EARNS ITS KEEP. What
 * it prevents is a browser-executable document — SVG, HTML — hiding behind an image or video
 * name. Widening it to more media families changes nothing; widening it to `application` would
 * destroy it entirely.
 *
 * ⚠️ THIS TABLE LIVES ON ITS OWN because two collaborators need it and neither owns it: the
 * upload validator asks "may this extension carry this type?", the type resolver asks "what is
 * this file, when sniffing has no opinion?". Holding one copy each guarantees they drift.
 */
final class ExtensionFamilies
{
    /**
     * ⚠️ THE FIRST ENTRY IS THE PRIMARY FAMILY. It is what a file is classified as when content
     * sniffing yields nothing usable — which is why `ogg` lists audio first and `wmv` video
     * first, matching what those extensions mean in practice.
     *
     * @var array<string, array<int, string>>
     */
    public const TABLE = [
        'jpg' => ['image'], 'jpeg' => ['image'], 'png' => ['image'], 'gif' => ['image'],
        'webp' => ['image'], 'avif' => ['image'], 'bmp' => ['image'],
        'heic' => ['image'], 'heif' => ['image'],

        'mp4' => ['video'], 'm4v' => ['video'], 'mov' => ['video'],
        '3gp' => ['video'], '3g2' => ['video'], 'webm' => ['video'], 'mkv' => ['video'],
        'avi' => ['video'], 'flv' => ['video'], 'mpg' => ['video'], 'mpeg' => ['video'],
        'ts' => ['video'], 'm2ts' => ['video'], 'mts' => ['video'],

        /* ASF and Ogg carry either sound or moving pictures, indifferently. */
        'wmv' => ['video', 'audio'], 'asf' => ['video', 'audio'], 'wma' => ['video', 'audio'],
        'ogv' => ['video', 'audio'], 'ogg' => ['audio', 'video'], 'oga' => ['audio', 'video'],

        'mp3' => ['audio'], 'wav' => ['audio'], 'm4a' => ['audio'],
        'aac' => ['audio'], 'flac' => ['audio'],
    ];

    /**
     * The MIME type meaning "the sniffer has no opinion".
     *
     * ⚠️ IT IS NOT A CONTRADICTION, IT IS AN ABSENCE OF EVIDENCE, and treating the two alike
     * rejects perfectly valid files. The signature database is compiled into the `fileinfo`
     * extension and therefore differs between PHP builds: measured, an M2TS stream is
     * recognised on PHP 8.4 and unknown on 8.2 and 8.3. Refusing on "unknown" made the same
     * upload succeed or fail depending on the runtime, with no way to tell why.
     */
    public const NO_OPINION = 'application/octet-stream';

    /**
     * @return array<int, string>|null  null when the extension is not covered by this table
     */
    public static function for(string $extension): ?array
    {
        return self::TABLE[strtolower(trim($extension))] ?? null;
    }

    /** The family an extension stands for when nothing else can be established. */
    public static function primary(string $extension): ?string
    {
        return self::for($extension)[0] ?? null;
    }
}
