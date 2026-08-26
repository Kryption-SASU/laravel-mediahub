<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Kryption\MediaHub\Support\ArchiveCapacity;
use Kryption\MediaHub\Support\ArchiveProgress;
use Kryption\MediaHub\Support\RuntimeLimits;
use Kryption\MediaHub\Support\ServerRuntime;

/**
 * WHAT THIS CONFIGURATION PROMISES, AND WHAT THIS MACHINE WILL ACTUALLY DO.
 *
 * ⚠️ A CEILING THE RUNTIME REFUSES IS WORSE THAN A LOW ONE. `uploads.max_size` at two hundred
 * megabytes on a PHP whose `post_max_size` is eight does not accept two hundred: it refuses
 * everything above eight, before a single line of this package runs, with an empty body and no
 * reason. The host reads their own configuration, sees two hundred, and reports a broken
 * uploader — which is the one bug report nobody can act on.
 *
 * ⚠️ SO THIS SAYS WHAT TO CHANGE, NOT MERELY WHAT IS WRONG. "post_max_size is too small" sends
 * somebody to a search engine; "set post_max_size to at least 200M, or lower
 * mediahub.uploads.max_size to 8000" is a decision they can take in one minute.
 *
 * ⚠️ AND IT ADMITS WHAT IT CANNOT SEE. What really cuts a long download is set outside the
 * process — by the pool manager, or the front-end server, or a CDN — and none of it is readable
 * from in here. The report says so rather than implying the machine has been fully examined: a
 * diagnosis that overstates its reach is one people trust in the wrong places.
 *
 * ⚠️ WHICH ALSO MEANS THE ADVICE IS NOT THE SAME ON EVERY MACHINE. `request_terminate_timeout`
 * is exact under PHP-FPM and does not exist under mod_php, where nothing bounds a request's
 * duration at all. Every sentence about a timeout therefore comes from the runtime family this
 * process is actually in — see {@see ServerRuntime}.
 */
final class DiagnoseSetup
{
    /* ⚠️ THE THREE LEVELS ARE THE WHOLE VOCABULARY. A finding is either something that is
     * already failing, something that will fail on a bad day, or a fact worth knowing. */
    public const FAILING = 'error';

    public const RISKY = 'warning';

    public const FINE = 'ok';

    public function __construct(
        private readonly Config $config,
        private readonly Translator $translator,
        private readonly RuntimeLimits $limits,
        private readonly ArchiveCapacity $capacity,
        private readonly ServerRuntime $runtime,
        private readonly ArchiveProgress $progress,
    ) {
    }

    /**
     * @return array{ok: bool, checks: array<int, array{id: string, level: string, title: string, detail: string, recommendation: string|null}>}
     */
    public function __invoke(): array
    {
        $checks = [
            $this->runtimeCheck(),
            ...$this->uploadChecks(),
            $this->archiveCapacityCheck(),
            $this->executionTimeCheck(),
            $this->progressCheck(),
            $this->bufferingCheck(),
            $this->imageMemoryCheck(),
            ...$this->extensionChecks(),
        ];

        return [
            'ok' => ! in_array(self::FAILING, array_column($checks, 'level'), true),
            'checks' => $checks,
        ];
    }

    /**
     * WHICH PHP IS ANSWERING — because every other sentence in this report depends on it.
     *
     * ⚠️ THE CONSOLE IS THE ONE ANSWER THAT MAKES THE REPORT MISLEADING. Run from a terminal,
     * every number below is the command line's: its memory limit, its execution time, its
     * extensions. A separate `php.ini` for the console is the normal arrangement rather than an
     * exotic one, so the report is not merely incomplete — it is confidently wrong about a
     * machine nobody asked about. Saying so is the only honest thing to do with it.
     *
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function runtimeCheck(): array
    {
        $words = ['sapi' => $this->runtime->sapi(), 'timeouts' => $this->timeoutPhrase()];

        return $this->runtime->servesTheWeb()
            ? $this->finding('runtime.sapi', self::FINE, $words, null)
            : $this->finding('runtime.sapi', self::RISKY, $words, $words);
    }

    /**
     * ⚠️ TWO DIRECTIVES, NOT ONE, AND THE SMALLER ALWAYS WINS. `upload_max_filesize` bounds the
     * file; `post_max_size` bounds the whole request, which is the file plus its fields plus the
     * multipart overhead. Raising one and not the other is the commonest way to spend an
     * afternoon on an uploader that refuses at a size neither of them names.
     *
     * @return array<int, array{id: string, level: string, title: string, detail: string, recommendation: string|null}>
     */
    private function uploadChecks(): array
    {
        /* The package states its own ceiling in kilobytes. */
        $wanted = max(0, (int) $this->config->get('mediahub.uploads.max_size', 0)) * 1024;

        if ($wanted === 0) {
            return [];
        }

        $checks = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $allowed = $this->limits->bytes($directive);

            $checks[] = $allowed !== null && $allowed < $wanted
                ? $this->finding(
                    'uploads.'.$directive,
                    self::FAILING,
                    ['directive' => $directive, 'allowed' => $this->human($allowed), 'wanted' => $this->human($wanted)],
                    ['directive' => $directive, 'wanted' => $this->human($wanted), 'kilobytes' => (string) intdiv($allowed ?? 0, 1024)],
                    'uploads.fix',
                )
                : $this->finding(
                    'uploads.'.$directive,
                    self::FINE,
                    ['directive' => $directive, 'allowed' => $allowed === null ? '∞' : $this->human($allowed), 'wanted' => $this->human($wanted)],
                    null,
                );
        }

        return $checks;
    }

    /**
     * ⚠️ THE ARCHIVE IS THE ONE PLACE WHERE A CEILING IS A GUESS, and the report says so. What
     * cuts a long download is invisible from here, so the package works from a budget the host
     * declares — and, when nobody has declared one, from a deliberately modest assumption.
     *
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function archiveCapacityCheck(): array
    {
        $configured = (int) $this->config->get('mediahub.archives.max_bytes', 0);
        $deliverable = $this->capacity->ceiling();
        $declared = $this->capacity->isDeclared();

        $words = [
            'configured' => $configured > 0 ? $this->human($configured) : '∞',
            'deliverable' => $this->human($deliverable),

            /* ⚠️ THE ADVICE NAMES THIS MACHINE'S TIMEOUTS, not one family's. Sending an Apache
             * host to `php-fpm.conf` costs them the afternoon this report was meant to save. */
            'timeouts' => $this->timeoutPhrase(),
        ];

        if ($configured > 0 && $configured <= $deliverable) {
            return $this->finding('archives.capacity', self::FINE, $words, null);
        }

        /* ⚠️ A WARNING RATHER THAN A FAILURE, BECAUSE NOTHING IS BROKEN YET. The package already
         * refuses beyond what it believes it can deliver; what this reports is that the number
         * in the configuration is not the number in force. */
        return $this->finding(
            'archives.capacity',
            self::RISKY,
            $words,
            $words,
            $declared ? 'archives.capacity.lower' : 'archives.capacity.declare',
        );
    }

    /**
     * THE ONE CEILING A CLASSIC PHP CAN ACTUALLY HIT WHILE STREAMING.
     *
     * ⚠️ `max_execution_time` IS NOT A WALL CLOCK, AND THAT IS MEASURED RATHER THAN REMEMBERED.
     * A script blocked on input and output runs past its limit indefinitely — a probe held on a
     * pipe survived fifteen seconds under a limit of two, while the same limit killed a busy
     * loop at 2.1. So waiting for a remote object store, which is nearly all of what streaming
     * an archive consists of, does not count against it.
     *
     * ⚠️ BUT COMPRESSING DOES, and that is why this is here. Deflating a few gigabytes of files
     * that are not already compressed is processor work, it accumulates, and when it reaches the
     * limit the script is killed after the 200 and the first bytes have gone — the truncated
     * archive this package exists to avoid handing anyone.
     *
     * ⚠️ NORMALLY THE PACKAGE JUST LIFTS IT. `set_time_limit(0)` before the first byte settles
     * the whole question — except where `disable_functions` has taken the function away, which
     * is ordinary on shared hosting, and is exactly the kind of host that runs classic PHP
     * rather than a pool it configured itself.
     *
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function executionTimeCheck(): array
    {
        $limit = $this->limits->seconds('max_execution_time');
        $liftable = $this->runtime->canLiftTheTimeLimit();

        $words = [
            'limit' => $limit === null ? '∞' : (string) $limit,
            'because' => (string) $this->translator->get(
                'mediahub::diagnostics.archives.execution_time.because.'.($limit === null ? 'absent' : 'lifted'),
                ['limit' => (string) $limit],
            ),
        ];

        return $limit === null || $liftable
            ? $this->finding('archives.execution_time', self::FINE, $words, null)
            : $this->finding('archives.execution_time', self::RISKY, $words, $words);
    }

    /**
     * WHETHER A DOWNLOAD CAN BE WATCHED AT ALL ON THIS INSTALLATION.
     *
     * ⚠️ A FEATURE THAT SILENTLY DOES NOTHING IS WORSE THAN ONE THAT IS ABSENT. The progress bar
     * needs a cache two requests can meet in; on `array` or `null` the answer is always "never
     * heard of it", so no bar ever appears and nothing anywhere says why. The screen still works
     * — it falls back to knowing that the answer has begun — which is exactly what makes the
     * absence hard to attribute to a cache setting three files away.
     *
     * ⚠️ AND IT IS A WARNING, NOT A FAILURE. Nothing is broken: archives download. What is lost
     * is a number, and calling that an error would teach people to close the report.
     *
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function progressCheck(): array
    {
        $words = ['store' => $this->progress->name()];

        return $this->progress->isShared()
            ? $this->finding('archives.progress', self::FINE, $words, null)
            : $this->finding('archives.progress', self::RISKY, $words, $words);
    }

    /**
     * WHERE THIS MACHINE'S REAL DOWNLOAD CEILING IS WRITTEN, in words somebody can act on.
     *
     * ⚠️ ONE PHRASE PER RUNTIME FAMILY, HELD IN THE CATALOGUE. The recommendations that use it
     * are the same sentence everywhere; only the place to go and look changes, and that is
     * precisely the part a report must not get wrong.
     */
    private function timeoutPhrase(): string
    {
        return (string) $this->translator->get('mediahub::diagnostics.runtime.timeouts.'.$this->runtime->family());
    }

    /**
     * ⚠️ BUFFERING TURNS A STREAM BACK INTO A FILE, IN MEMORY. With `zlib.output_compression` on,
     * every byte of a two-gigabyte archive is held in the process before any of it is sent —
     * which is the exact failure streaming exists to avoid, and it arrives as an exhausted
     * memory limit rather than as anything mentioning archives.
     *
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function bufferingCheck(): array
    {
        if (! $this->limits->flag('zlib.output_compression')) {
            return $this->finding('archives.buffering', self::FINE, [], null);
        }

        /* Turned on, but the package can still turn it off for its own response. */
        if ($this->limits->canSet('zlib.output_compression')) {
            return $this->finding('archives.buffering', self::RISKY, [], []);
        }

        return $this->finding('archives.buffering', self::FAILING, [], []);
    }

    /**
     * ⚠️ IT IS THE PIXELS THAT EXHAUST PHP, NOT THE FILE SIZE. GD holds a decoded image at
     * roughly four bytes a pixel whatever the file weighed: a fifty-megapixel photograph is two
     * hundred megabytes of memory from a file of six. A limit that looks generous next to the
     * upload ceiling is not one next to the decoder.
     *
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function imageMemoryCheck(): array
    {
        $pixels = (int) $this->config->get('mediahub.uploads.max_image_pixels', 0);
        $limit = $this->limits->bytes('memory_limit');

        if ($pixels <= 0 || $limit === null) {
            return $this->finding('images.memory', self::FINE, ['needed' => '—', 'limit' => '∞'], null);
        }

        $needed = $pixels * 4;
        $words = ['needed' => $this->human($needed), 'limit' => $this->human($limit), 'megapixels' => (string) intdiv($pixels, 1_000_000)];

        return $needed > $limit
            ? $this->finding('images.memory', self::RISKY, $words, $words)
            : $this->finding('images.memory', self::FINE, $words, null);
    }

    /**
     * @return array<int, array{id: string, level: string, title: string, detail: string, recommendation: string|null}>
     */
    private function extensionChecks(): array
    {
        $driver = (string) $this->config->get('mediahub.images.driver', 'gd');

        $wanted = ['zip' => self::FAILING, 'fileinfo' => self::FAILING];

        /* ⚠️ "none" IS A NORMAL STATE. A host that stores documents needs no image library, and
         * reporting a missing one as a fault would train people to ignore the report. */
        if ($driver === 'gd' || $driver === 'imagick') {
            $wanted[$driver] = self::RISKY;
        }

        $checks = [];

        foreach ($wanted as $extension => $whenMissing) {
            $present = extension_loaded($extension);

            $checks[] = $this->finding(
                'extensions.'.$extension,
                $present ? self::FINE : $whenMissing,
                ['extension' => $extension],
                $present ? null : ['extension' => $extension],
                'extensions.fix',
            );
        }

        return $checks;
    }

    /**
     * ⚠️ THE WORDS COME FROM THE CATALOGUE, KEYED ON THE FINDING AND ITS LEVEL. A report read by
     * the person configuring the package is a report they read in their own language — and the
     * recommendation is the half that has to be, since it is the half that asks them to act.
     *
     * @param  array<string, string>  $words
     * @param  array<string, string>|null  $advice  null when there is nothing to put right
     * @return array{id: string, level: string, title: string, detail: string, recommendation: string|null}
     */
    private function finding(string $id, string $level, array $words, ?array $advice, ?string $adviceKey = null): array
    {
        return [
            'id' => $id,
            'level' => $level,
            'title' => (string) $this->translator->get('mediahub::diagnostics.'.$id.'.title', $words),
            'detail' => (string) $this->translator->get('mediahub::diagnostics.'.$id.'.'.$level, $words),
            'recommendation' => $advice === null
                ? null
                : (string) $this->translator->get('mediahub::diagnostics.'.($adviceKey ?? $id.'.fix'), $advice),
        ];
    }

    /** ⚠️ READ BY A PERSON, so bytes are not what goes on the screen. */
    private function human(int $bytes): string
    {
        foreach ([['G', 1024 ** 3], ['M', 1024 ** 2], ['K', 1024]] as [$suffix, $step]) {
            if ($bytes >= $step) {
                return rtrim(rtrim(number_format($bytes / $step, 1, '.', ''), '0'), '.').$suffix;
            }
        }

        return $bytes.'B';
    }
}
