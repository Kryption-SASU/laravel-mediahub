<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Fixtures;

/**
 * FILES THAT ARE NOT IMAGES, and that `finfo` confirms as such.
 *
 * ⚠️ THAT POINT IS THE WHOLE TEST. Claiming a video produces no thumbnail only means something
 * if the file presented is RECOGNISED as a video by the same function that decides in
 * production. An `.mp4` full of zeros would be seen as `application/octet-stream`, validation
 * would refuse it, and the test would be green having exercised nothing.
 *
 * ⚠️ THESE ARE HEADERS, NOT PLAYABLE FILES. `finfo` only looks at the first bytes, and that is
 * exactly what we want to measure: recognition of the CONTAINER. No player could play these
 * files, and no test asks it to.
 *
 * ⚠️ EVERY LINE WAS PRESENTED TO `finfo_file()`, and the observed type is written next to it —
 * including the two surprises: `video/MP2T` comes back in CAPITALS, and `.wma` comes out as
 * `video/x-ms-asf` because Windows audio and video share the ASF container.
 */
final class SampleFiles
{
    private static function zeros(int $n): string
    {
        return str_repeat("\x00", $n);
    }

    /**
     * EVERY VIDEO CONTAINER THE PACKAGE ACCEPTS — Apple, Android, Windows and the rest.
     *
     * @return array<string, array{0: string, 1: string}> extension => [bytes, observed type]
     */
    public static function videos(): array
    {
        $z = self::zeros(20);
        $ts = str_repeat("\x47\x40\x00\x10".self::zeros(184), 4);
        $m2ts = str_repeat(self::zeros(4)."\x47\x40\x00\x10".self::zeros(184), 4);
        $asf = "\x30\x26\xb2\x75\x8e\x66\xcf\x11\xa6\xd9\x00\xaa\x00\x62\xce\x6c".self::zeros(30);

        return [
            /* Apple — QuickTime and its variants. */
            'mov' => ["\x00\x00\x00\x14ftypqt  \x00\x00\x02\x00qt  \x00\x00\x00\x08wide\x00\x00\x00\x1cmdat".$z, 'video/quicktime'],
            'm4v' => ["\x00\x00\x00\x18ftypM4V \x00\x00\x00\x00M4V mp42isom\x00\x00\x00\x08free", 'video/x-m4v'],
            'mp4' => ["\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free", 'video/mp4'],

            /* Android. */
            '3gp' => ["\x00\x00\x00\x18ftyp3gp4\x00\x00\x03\x003gp4isom\x00\x00\x00\x08free", 'video/3gpp'],
            '3g2' => ["\x00\x00\x00\x18ftyp3g2a\x00\x00\x03\x003g2aisom\x00\x00\x00\x08free", 'video/3gpp2'],
            'webm' => ["\x1a\x45\xdf\xa3\x01\x00\x00\x00\x00\x00\x00\x1f\x42\x86\x81\x01\x42\xf7\x81\x01\x42\xf2\x81\x04\x42\xf3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02", 'video/webm'],
            'mkv' => ["\x1a\x45\xdf\xa3\x01\x00\x00\x00\x00\x00\x00\x23\x42\x86\x81\x01\x42\xf7\x81\x01\x42\xf2\x81\x04\x42\xf3\x81\x08\x42\x82\x88matroska\x42\x87\x81\x04\x42\x85\x81\x02", 'video/x-matroska'],

            /* Windows — AVI, and ASF for WMV. */
            'avi' => ["RIFF\x24\x01\x00\x00AVI LIST\x10\x00\x00\x00hdrlavih".self::zeros(56), 'video/x-msvideo'],
            'wmv' => [$asf, 'video/x-ms-asf'],
            'asf' => [$asf, 'video/x-ms-asf'],

            /* The rest, common on cameras and older archives. */
            'flv' => ["FLV\x01\x05\x00\x00\x00\x09\x00\x00\x00\x00".self::zeros(16), 'video/x-flv'],
            'ogv' => ["OggS\x00\x02".$z."\x01\x1e\x01video".$z, 'video/ogg'],
            'mpg' => ["\x00\x00\x01\xba\x21\x00\x01\x00\x01\x80\x01\x00\x01".$z, 'video/mpeg'],
            'mpeg' => ["\x00\x00\x01\xb3\x16\x00\xf0\xc4\x02\x0a\x24\x60".$z, 'video/mpeg'],

            /* AVCHD — the type comes back in CAPITALS, and nothing else does. */
            'ts' => [$ts, 'video/MP2T'],
            'm2ts' => [$m2ts, 'video/MP2T'],
            'mts' => [$m2ts, 'video/MP2T'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> extension => [bytes, observed type]
     */
    public static function audios(): array
    {
        return [
            'mp3' => ["ID3\x04\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 32), 'audio/mpeg'],
            'wav' => ["RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xac\x00\x00".self::zeros(20), 'audio/x-wav'],
            'ogg' => ["OggS\x00\x02".self::zeros(20)."\x01\x1e\x01vorbis".self::zeros(20), 'audio/ogg'],
            'oga' => ["OggS\x00\x02".self::zeros(20)."\x01\x1e\x01vorbis".self::zeros(20), 'audio/ogg'],
            'm4a' => ["\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A mp42isom\x00\x00\x00\x08free", 'audio/x-m4a'],
            'aac' => ["\xff\xf1\x50\x80\x00\x1f\xfc".self::zeros(30), 'audio/x-hx-aac-adts'],
            'flac' => ["fLaC\x00\x00\x00\x22".self::zeros(34), 'audio/flac'],

            /*
             * ⚠️ THE WINDOWS TRAP: a `.wma` is an ASF container, the SAME as a `.wmv`. `finfo`
             * therefore answers `video/…` for an AUDIO file. A rule demanding "audio extension
             * ⇒ audio type" would refuse every WMA, and the refusal would be undecipherable to
             * whoever is uploading.
             */
            'wma' => ["\x30\x26\xb2\x75\x8e\x66\xcf\x11\xa6\xd9\x00\xaa\x00\x62\xce\x6c".self::zeros(30), 'video/x-ms-asf'],
        ];
    }

    public static function mp4(): string
    {
        return self::videos()['mp4'][0];
    }

    public static function mp3(): string
    {
        return self::audios()['mp3'][0];
    }

    /**
     * THE HEADER OF AN IPHONE PHOTO — recognised as `image/heic`.
     *
     * ⚠️ THIS IS NOT A DECODABLE IMAGE, AND THIS FILE DOES NOT PRETEND OTHERWISE. None of this
     * repository's benches has a working libheif: ImageMagick ANNOUNCES HEIC there —
     * `queryFormats()` answers yes — but can neither write nor read one. A decodable sample
     * therefore cannot be built, and pretending to have one would turn the tests green on an
     * illusion.
     *
     * What this header does exercise remains the essential part: type recognition, passing
     * validation, and storage byte for byte.
     */
    public static function heic(): string
    {
        return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1\x00\x00\x00\x08free";
    }

    /** Recognised as `text/plain`. */
    public static function txt(): string
    {
        return "Meeting minutes.\nNothing to convert here.\n";
    }
}
