<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Conversions;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Support\ExternalTools;

/**
 * THE FIRST PAGE OF A DOCUMENT, DRAWN.
 *
 * ⚠️ IMAGEMAGICK CANNOT DO THIS, WHATEVER IT SAYS. `queryFormats()` answers "yes" for PDF on
 * machines where reading one is refused outright — Debian ships `<policy domain="coder"
 * rights="none" pattern="PDF" />` to this day, on a package thirteen security revisions past the
 * version it names. Measured here and on the production server, which has no imagick at all.
 *
 * ⚠️ AND THE REASON FOR THAT POLICY IS GHOSTSCRIPT, reached automatically by ImageMagick's
 * delegate machinery as soon as a file looks like PostScript. This driver never goes near that
 * path: it calls a renderer itself, on a file whose type has already been established, with no
 * shell and no format guessing.
 *
 * ⚠️ POPPLER IS PREFERRED, GHOSTSCRIPT IS ACCEPTED. `pdftoppm` only ever draws pages; `gs` is a
 * complete PostScript interpreter, a language with loops and file access. Refusing the second
 * would help nobody who already has it — see {@see ExternalTools::PDF_RENDERERS}.
 *
 * ⚠️ THE PAGE IS NEVER CROPPED, even when the definition asks for `cover`. A document is
 * recognised by its head — the letterhead, the title, the logo — and a square crop of an A4 page
 * removes exactly that. It is fitted inside the box instead, and the screen may letterbox it.
 */
final class PdfConversionDriver implements ConversionDriver
{
    private const OUTPUT = 'image/png';

    /** ⚠️ A PAGE FULL OF VECTOR ART CAN BE SLOW, and a queue worker held on one is a queue stopped. */
    private const SECONDS = 30;

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ExternalTools $tools,
        private readonly SourceFile $sources,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        return strtolower($mimeType) === 'application/pdf'
            && $this->tools->pdfRenderer() !== null;
    }

    public function outputMimeType(string $sourceMimeType): string
    {
        return self::OUTPUT;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function convert(string $disk, string $path, string $target, array $definition): array
    {
        $renderer = $this->tools->pdfRenderer();

        if ($renderer === null) {
            throw new \RuntimeException('conversion_tool_missing');
        }

        $box = max(
            max(1, (int) ($definition['width'] ?? 256)),
            max(1, (int) ($definition['height'] ?? 256)),
        );

        return $this->sources->withLocalCopy($disk, $path, function (string $local) use (
            $renderer, $disk, $target, $box
        ): array {
            return $this->sources->withScratchFile('png', function (string $page) use (
                $renderer, $local, $disk, $target, $box
            ): array {
                $answer = $this->tools->run(
                    $this->command($renderer, $local, $page, $box),
                    self::SECONDS,
                );

                $bytes = $this->drawn($page);

                /*
                 * ⚠️ THE FILE IS CHECKED, NOT THE EXIT CODE, and that is measured rather than
                 * cautious: `pdftoppm` exits ZERO after printing "I/O Error: Couldn't open file"
                 * — the same trap that once put an error message on screen where a version
                 * belonged.
                 */
                if ($bytes === '') {
                    throw new \RuntimeException('conversion_page_empty: '.trim($answer['err']));
                }

                $this->filesystems->disk($disk)->put($target, $bytes);

                $size = @getimagesizefromstring($bytes);

                return [
                    'path' => $target,
                    'disk' => $disk,
                    'mime_type' => self::OUTPUT,
                    'width' => is_array($size) ? (int) $size[0] : null,
                    'height' => is_array($size) ? (int) $size[1] : null,
                    'size' => strlen($bytes),
                ];
            });
        });
    }

    /**
     * ⚠️ TWO PROGRAMS, TWO GRAMMARS, AND NEITHER FORGIVES THE OTHER'S. This is why
     * {@see ExternalTools::pdfRenderer} reports a NAME beside the path: a caller given only a
     * path would have to guess from the file name, and a host who put poppler somewhere unusual
     * would get Ghostscript's flags.
     *
     * @param  array{name: string, path: string}  $renderer
     * @return array<int, string>
     */
    private function command(array $renderer, string $local, string $page, int $box): array
    {
        if ($renderer['name'] === 'gs') {
            return [
                $renderer['path'],
                /* ⚠️ `-dSAFER` FIRST. It is the default from 9.50 onwards and stating it costs
                 * nothing — a host on an older build is exactly the one that needs it. */
                '-dSAFER',
                '-dNOPAUSE',
                '-dBATCH',
                '-dQUIET',
                '-sDEVICE=png16m',
                '-r72',
                '-dFirstPage=1',
                '-dLastPage=1',
                '-o', $page,
                $local,
            ];
        }

        return [
            $renderer['path'],
            '-f', '1',
            '-l', '1',

            /*
             * ⚠️ `-singlefile` OR THE NAME IS A GUESS. Without it poppler appends the page
             * number — and pads it to the width of the page count, so the same command writes
             * `out-1.png` on a nine-page document and `out-01.png` on a twelve-page one. Nothing
             * would then find the file it had just produced.
             */
            '-singlefile',
            '-png',
            '-scale-to', (string) $box,
            $local,

            /* ⚠️ POPPLER TAKES A PREFIX AND ADDS THE EXTENSION ITSELF. */
            substr($page, 0, -4),
        ];
    }

    /** ⚠️ POPPLER WRITES `prefix.png`; GHOSTSCRIPT WRITES THE NAME IT WAS GIVEN. */
    private function drawn(string $page): string
    {
        return is_file($page) ? (string) file_get_contents($page) : '';
    }
}
