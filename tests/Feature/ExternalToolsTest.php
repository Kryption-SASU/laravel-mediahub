<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Kryption\MediaHub\Support\ExternalTools;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE PROGRAMS THE PACKAGE ASKS THE MACHINE FOR.
 *
 * ⚠️ THE BENCH BUILDS ITS OWN TOOLS, and that is what makes it a bench rather than a description
 * of this laptop. Asserting against the real ffmpeg would pass here, fail in a container without
 * it, and prove nothing either way about the branch that matters — the one where a host has
 * written a path that does not work.
 */
class ExternalToolsTest extends TestCase
{
    private string $bin;

    /**
     * ⚠️ THE SEARCH PATH IS PROCESS-WIDE, SO IT IS GIVEN BACK. A bench that narrows it and walks
     * away has narrowed it for every test that follows, and the suite then fails somewhere else
     * entirely — which reads as a flaky test rather than as this one.
     */
    private string $wasPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bin = sys_get_temp_dir().'/mediahub-tools-'.getmypid();
        $this->wasPath = (string) getenv('PATH');

        $this->app['files']->ensureDirectoryExists($this->bin);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->bin);

        putenv('PATH='.$this->wasPath);
        $_SERVER['PATH'] = $this->wasPath;
        $_ENV['PATH'] = $this->wasPath;

        parent::tearDown();
    }

    /** A program that prints what it was told to, and is genuinely executable. */
    private function fake(string $name, string $body): string
    {
        $path = $this->bin.'/'.$name;

        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0o755);

        return $path;
    }

    private function tools(): ExternalTools
    {
        return $this->app->make(ExternalTools::class);
    }

    // ── Where a tool is ──────────────────────────────────────────────────────

    public function test_a_configured_path_is_used_exactly_as_it_is(): void
    {
        $path = $this->fake('my-ffmpeg', 'echo "ffmpeg version 9.9.9"');

        $this->app['config']->set('mediahub.tools.ffmpeg', $path);

        $this->assertSame($path, $this->tools()->path(ExternalTools::FFMPEG));
    }

    /**
     * ⚠️ A PATH THAT DOES NOT WORK IS NOT QUIETLY REPLACED. Falling back to the PATH would run a
     * different program than the one that was named and say nothing about it — the host goes on
     * believing their setting is in force, and the version on their screen is somebody else's.
     */
    public function test_a_configured_path_that_is_not_executable_is_not_swapped_for_another(): void
    {
        $this->app['config']->set('mediahub.tools.ffmpeg', $this->bin.'/nothing-here');

        $found = $this->tools()->resolve(ExternalTools::FFMPEG);

        $this->assertNull($found['path']);

        /* ⚠️ AND IT IS REMEMBERED AS CONFIGURED, which is the whole point: "there is no ffmpeg
         * here" and "you named one and it is wrong" call for opposite actions. */
        $this->assertTrue($found['configured']);
    }

    /** ⚠️ A DIRECTORY IS NOT A PROGRAM, and handing one to a process raises where nobody looks. */
    public function test_a_directory_is_not_taken_for_a_program(): void
    {
        $this->app['config']->set('mediahub.tools.ffmpeg', $this->bin);

        $this->assertNull($this->tools()->path(ExternalTools::FFMPEG));
    }

    /** ⚠️ AND A FILE THAT IS NOT EXECUTABLE IS NOT ONE EITHER. */
    public function test_a_file_nobody_can_run_is_not_taken_for_a_program(): void
    {
        $path = $this->bin.'/readme.txt';

        file_put_contents($path, 'not a program');
        chmod($path, 0o644);

        $this->app['config']->set('mediahub.tools.ffmpeg', $path);

        $this->assertNull($this->tools()->path(ExternalTools::FFMPEG));
    }

    // ── What it says it is ───────────────────────────────────────────────────

    public function test_the_version_is_the_first_line_of_the_answer(): void
    {
        $path = $this->fake('teller', 'echo "toolname version 4.2.1"; echo "built with things"');

        $this->assertSame('toolname version 4.2.1', $this->tools()->version($path));
    }

    /** ⚠️ AND STANDARD ERROR COUNTS: `pdftoppm` answers there, and it is not alone. */
    public function test_a_version_written_to_standard_error_is_read(): void
    {
        $path = $this->fake('shouter', 'echo "shouter version 1.0" >&2');

        $this->assertSame('shouter version 1.0', $this->tools()->version($path));
    }

    /**
     * ⚠️ THE FLAG IS NOT AGREED ON, AND THE EXIT CODE DOES NOT SETTLE IT. Measured: given
     * `-version`, `pdftoppm` takes it for a file name, prints "I/O Error: Couldn't open file
     * '-version'" — and exits ZERO. A rule reading the first answer that succeeded put that
     * sentence on the screen where a version belonged, which is what shipped for an afternoon.
     */
    public function test_an_error_that_exits_zero_is_not_read_as_a_version(): void
    {
        $path = $this->fake('poppler-ish', <<<'SH'
            case "$1" in
                -v) echo "poppler-ish version 22.12.0" >&2 ;;
                *)  echo "I/O Error: Couldn't open file '$1': No such file or directory." ;;
            esac
            exit 0
            SH);

        $this->assertSame('poppler-ish version 22.12.0', $this->tools()->version($path));
    }

    /**
     * ⚠️ AND A PROBE THAT CANNOT SAY NO IS NOT A PROBE. Without this, the rule above could be
     * "return whatever came back" and every bench here would still be green.
     */
    public function test_a_program_that_answers_nothing_useful_has_no_version(): void
    {
        $path = $this->fake('mute', 'echo "no idea what you are asking"');

        $this->assertNull($this->tools()->version($path));
    }

    // ── Running one ──────────────────────────────────────────────────────────

    /**
     * ⚠️ NO SHELL, AND THIS IS THE ASSERTION THAT SAYS SO. An argument carrying a semicolon and a
     * second command comes back as text, in one piece: there is no command line for it to break
     * out of, which is why nothing here escapes anything.
     */
    public function test_an_argument_is_never_a_command(): void
    {
        $path = $this->fake('echoer', 'printf "%s" "$1"');

        $answer = $this->tools()->run([$path, 'a; echo pwned > /tmp/mediahub-pwned'], 5);

        $this->assertSame('a; echo pwned > /tmp/mediahub-pwned', $answer['out']);
        $this->assertFileDoesNotExist('/tmp/mediahub-pwned');
    }

    /** ⚠️ AND IT IS BOUNDED: a program given a malformed file can sit for ever, holding a worker. */
    public function test_a_program_that_never_returns_is_cut_off(): void
    {
        $path = $this->fake('sleeper', 'sleep 30');

        $started = microtime(true);
        $answer = $this->tools()->run([$path], 1);

        $this->assertFalse($answer['ok']);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    // ── Which renderer draws a PDF ───────────────────────────────────────────

    /**
     * ⚠️ POPPLER FIRST WHEN BOTH ARE THERE. Ghostscript is a complete PostScript interpreter —
     * a language with loops and file access — and that is what earned ImageMagick its worst
     * vulnerabilities, to the point where Debian still ships the PDF coder blocked today.
     * `pdftoppm` only ever draws pages.
     */
    public function test_poppler_is_preferred_over_ghostscript(): void
    {
        $this->fake('pdftoppm', 'echo "pdftoppm version 1.0" >&2');
        $this->fake('gs', 'echo "9.55.0"');

        $this->onlyOnPath($this->bin);

        $renderer = $this->tools()->pdfRenderer();

        $this->assertNotNull($renderer);
        $this->assertSame('pdftoppm', $renderer['name']);
    }

    /** ⚠️ AND GHOSTSCRIPT IS STILL ACCEPTED — refusing it would help nobody who already has it. */
    public function test_ghostscript_is_used_when_it_is_the_only_one(): void
    {
        $this->fake('gs', 'echo "9.55.0"');

        $this->onlyOnPath($this->bin);

        $renderer = $this->tools()->pdfRenderer();

        $this->assertNotNull($renderer);
        $this->assertSame('gs', $renderer['name']);
    }

    /**
     * ⚠️ THE NAME COMES FROM THE FILE, because the two take different arguments. A caller handed
     * only a path would have to guess, and a host who put poppler somewhere unusual would get
     * Ghostscript's flags.
     */
    public function test_a_configured_renderer_is_named_from_its_file_name(): void
    {
        $path = $this->fake('pdftoppm', 'echo "pdftoppm version 1.0" >&2');

        $this->app['config']->set('mediahub.tools.pdf', $path);

        $this->assertSame('pdftoppm', $this->tools()->pdfRenderer()['name']);
    }

    public function test_no_renderer_at_all_is_reported_as_none(): void
    {
        $this->onlyOnPath($this->bin);

        $this->assertNull($this->tools()->pdfRenderer());
    }

    /**
     * ⚠️ THE SEARCH PATH IS NARROWED TO THIS BENCH'S OWN DIRECTORY. Left alone, a machine that
     * happens to have poppler installed would answer for the test — and the test would pass on
     * that machine and nowhere else, while asserting nothing about the code.
     */
    private function onlyOnPath(string $directory): void
    {
        putenv('PATH='.$directory);
        $_SERVER['PATH'] = $directory;
        $_ENV['PATH'] = $directory;
    }
}
