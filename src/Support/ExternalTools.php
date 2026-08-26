<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * THE PROGRAMS THIS PACKAGE ASKS THE MACHINE FOR — where they are, and whether they answer.
 *
 * ⚠️ NO EXTENSION HERE CAN DRAW A VIDEO OR A PDF, AND THE ONES THAT CLAIM TO ARE LYING.
 * `Imagick::queryFormats()` announces `MP4`, `MOV` and `PDF` on both machines this was measured
 * on. The video ones go through a DELEGATE — which is ffmpeg itself — and every delegate is cut
 * by `policy.xml`; the PDF one is cut at the coder. So the thumbnail of a video or of a document
 * comes from a program, or it does not come at all.
 *
 * ⚠️ AND A PROGRAM IS RUN WITHOUT A SHELL, WHICH IS THE WHOLE SECURITY ARGUMENT. The command is
 * an array of arguments handed straight to the kernel: there is no line for anything to be
 * quoted into, so the question of escaping never arises. Nothing here builds a string, and
 * `escapeshellarg` appears nowhere — its presence would mean a shell was involved.
 *
 * ⚠️ A CONFIGURED PATH THAT DOES NOT WORK IS NOT THE SAME AS NO PATH AT ALL. One is a machine
 * without the tool; the other is somebody who wrote a path and believes it is in use. They call
 * for opposite actions, and telling them apart is the reason {@see resolve} reports which of the
 * two it found rather than answering `null` twice.
 */
final class ExternalTools
{
    public const FFMPEG = 'ffmpeg';

    public const FFPROBE = 'ffprobe';

    /**
     * WHAT CAN RENDER A PAGE OF A PDF, IN ORDER OF PREFERENCE.
     *
     * ⚠️ POPPLER BEFORE GHOSTSCRIPT, AND THE ORDER IS THE POINT. Ghostscript is a complete
     * PostScript interpreter — a programming language with loops and file access — and that is
     * exactly what earned ImageMagick its worst vulnerabilities, to the point where Debian still
     * ships `<policy domain="coder" rights="none" pattern="PDF" />` today, on a package thirteen
     * security revisions past the version it names. `pdftoppm` only ever draws pages.
     *
     * ⚠️ GHOSTSCRIPT IS STILL ACCEPTED, because refusing it would help nobody: a host that
     * already has it gets its thumbnails, and a host that has neither installs whichever it
     * prefers. What the package does not do is require the more dangerous of the two.
     */
    public const PDF_RENDERERS = ['pdftoppm', 'gs'];

    /** ⚠️ A PROBE THAT HANGS IS A HEALTH REPORT THAT HANGS. Asking a version is not slow work. */
    private const VERSION_SECONDS = 5;

    /**
     * ⚠️ THERE IS NO AGREED FLAG, AND THE EXIT CODE DOES NOT SETTLE IT. Measured here: given
     * `-version`, `pdftoppm` takes it for a file name, prints "I/O Error: Couldn't open file
     * '-version'" — and exits ZERO. So "take the first that succeeds" reports an error message
     * as a version, which is what shipped for an afternoon and was caught on a real screen.
     */
    private const VERSION_FLAGS = ['-version', '-v', '--version'];

    /** @var array<string, array{path: string|null, configured: bool}> */
    private array $found = [];

    public function __construct(
        private readonly Config $config,
        private readonly ExecutableFinder $finder = new ExecutableFinder(),
    ) {
    }

    /**
     * WHERE A TOOL IS, AND HOW THAT WAS DECIDED.
     *
     * `path` is null when nothing usable was found. `configured` says whether the host named one
     * — so that "you set a path and it is not an executable" can be told from "there is nothing
     * on this machine", which is the difference between a typo and an installation.
     *
     * @return array{path: string|null, configured: bool}
     */
    public function resolve(string $tool): array
    {
        return $this->found[$tool] ??= $this->look($tool);
    }

    public function path(string $tool): ?string
    {
        return $this->resolve($tool)['path'];
    }

    public function has(string $tool): bool
    {
        return $this->path($tool) !== null;
    }

    /**
     * WHICH PDF RENDERER THIS MACHINE WILL USE, if any.
     *
     * ⚠️ THE NAME MATTERS AS MUCH AS THE PATH, because the two take different arguments. A
     * caller handed only a path would have to guess from the file name — and a host who
     * configured `/opt/bin/pdfrender` would get the wrong ones.
     *
     * @return array{name: string, path: string}|null
     */
    public function pdfRenderer(): ?array
    {
        $configured = $this->configuredPath('pdf');

        if ($configured !== null) {
            return ['name' => $this->rendererNameOf($configured), 'path' => $configured];
        }

        foreach (self::PDF_RENDERERS as $candidate) {
            $found = $this->finder->find($candidate);

            if (is_string($found) && $this->usable($found)) {
                return ['name' => $candidate, 'path' => $found];
            }
        }

        return null;
    }

    /**
     * What the tool says it is, in one line.
     *
     * ⚠️ ASKED OF THE PROGRAM, NOT READ FROM A PACKAGE MANAGER. What matters is the binary that
     * will actually be run — a host may have three ffmpegs and a configured path pointing at the
     * one nobody expects.
     */
    public function version(string $path): ?string
    {
        foreach (self::VERSION_FLAGS as $flag) {
            $answer = $this->run([$path, $flag], self::VERSION_SECONDS);

            /* ⚠️ `pdftoppm -v` WRITES TO STANDARD ERROR, and so do several others. Reading only
             * standard output reports "no version" for a program that answered perfectly. */
            $text = trim($answer['out']) !== '' ? $answer['out'] : $answer['err'];
            $first = trim((string) strtok($text, "\n"));

            if ($this->looksLikeAVersion($first)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * RUNNING ONE, AND NEVER THROUGH A SHELL.
     *
     * ⚠️ THE ARGUMENTS ARE AN ARRAY. `Process` hands them to the kernel one by one, so a file
     * name holding a space, a quote or a semicolon is a file name and nothing else. Building a
     * command line and escaping it is the same job done worse.
     *
     * ⚠️ AND IT IS BOUNDED. A program given a malformed file can sit for ever; a request that
     * never returns holds a worker until the pool manager kills it, which is a far more
     * expensive failure than a missing thumbnail.
     *
     * @param  array<int, string>  $command
     * @return array{ok: bool, out: string, err: string}
     */
    public function run(array $command, int $seconds): array
    {
        $process = new Process($command);
        $process->setTimeout((float) $seconds);

        /* ⚠️ NOTHING IS TYPED AT IT. Without this, a program that decides to ask a question waits
         * on a terminal that will never answer, and the timeout above becomes the only way out. */
        $process->setInput('');

        try {
            $process->run();
        } catch (\Throwable) {
            return ['ok' => false, 'out' => '', 'err' => ''];
        }

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }

    /**
     * ⚠️ THE ANSWER IS JUDGED ON ITS CONTENT, because nothing else can judge it. A version line
     * carries a version: two numbers and a dot between them. "I/O Error: Couldn't open file
     * '-version'" carries none, which is exactly how it is told apart from `pdftoppm version
     * 22.12.0` — and the exit code, measured, is zero for both.
     */
    private function looksLikeAVersion(string $line): bool
    {
        return $line !== '' && preg_match('/\d+\.\d+/', $line) === 1;
    }

    /**
     * @return array{path: string|null, configured: bool}
     */
    private function look(string $tool): array
    {
        $configured = $this->configuredPath($tool);

        if ($configured !== null) {
            return ['path' => $configured, 'configured' => true];
        }

        /* ⚠️ CONFIGURED AND UNUSABLE IS REPORTED AS CONFIGURED, so the report can say so. Falling
         * back to a search here would find another binary and hide the mistake — the host would
         * go on believing their path is the one running. */
        if ($this->config->get('mediahub.tools.'.$tool) !== null) {
            return ['path' => null, 'configured' => true];
        }

        $found = $this->finder->find($tool);

        return [
            'path' => is_string($found) && $this->usable($found) ? $found : null,
            'configured' => false,
        ];
    }

    private function configuredPath(string $tool): ?string
    {
        $named = $this->config->get('mediahub.tools.'.$tool);

        if (! is_string($named) || trim($named) === '') {
            return null;
        }

        return $this->usable($named) ? $named : null;
    }

    /**
     * ⚠️ A FILE, AND EXECUTABLE, AND NOT A DIRECTORY. A path from a configuration file is data
     * from outside this program; handing a directory or a text file to `proc_open` produces an
     * error nobody connects to the setting they wrote.
     *
     * ⚠️ AND THE NULL BYTE IS REFUSED FIRST, because PHP's filesystem functions raise on it
     * rather than answering — a value that would turn a check into an exception.
     */
    private function usable(string $path): bool
    {
        return ! str_contains($path, "\0")
            && is_file($path)
            && is_executable($path);
    }

    /**
     * ⚠️ THE BASENAME DECIDES, because that is what the host is telling us they installed. A
     * configured `/opt/poppler/bin/pdftoppm` is poppler however it got there; anything this does
     * not recognise is treated as Ghostscript-shaped only if it says so.
     */
    private function rendererNameOf(string $path): string
    {
        $name = strtolower(basename($path));

        foreach (self::PDF_RENDERERS as $known) {
            if (str_contains($name, $known)) {
                return $known;
            }
        }

        return $name;
    }
}
