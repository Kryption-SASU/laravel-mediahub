<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * HOW LARGE AN ARCHIVE THIS MACHINE CAN ACTUALLY FINISH SENDING.
 *
 * ⚠️ AN ARCHIVE THAT DIES HALFWAY HAS ALREADY SENT ITS 200. The headers left before the first
 * file was read, so there is no status left to fail with: the browser saves a ZIP that opens,
 * lists most of its files, and is missing the rest. Nothing anywhere says so — not the server
 * log, which records a completed request, and not the person, who has a file. That is the
 * failure this class exists to prevent, and it is why the answer is a REFUSAL before the first
 * byte rather than a best effort.
 *
 * ⚠️ WHAT CUTS THE DOWNLOAD IS NOT VISIBLE FROM HERE, AND PRETENDING OTHERWISE WOULD BE WORSE
 * THAN NOT LOOKING. PHP's own `max_execution_time` is largely beside the point: on Unix it does
 * not count time spent waiting on input and output, which is nearly all of what streaming a
 * remote object store consists of. The real ceilings are PHP-FPM's `request_terminate_timeout`
 * and the front-end server's proxy timeout, and neither can be read from inside the process.
 *
 * ⚠️ SO THE BUDGET IS DECLARED, NOT DETECTED. A host that knows its own timeouts says so; one
 * that says nothing gets a deliberately modest assumption, because the cost of being wrong is
 * asymmetric — a refused archive is a sentence somebody reads, and a truncated one is a
 * corrupted file discovered weeks later.
 */
final class ArchiveCapacity
{
    /**
     * ⚠️ SIXTY SECONDS IS NOT A GUESS AT THIS MACHINE, it is the commonest default of the two
     * things that actually cut the download — and the point of the number is that it is
     * conservative rather than that it is right.
     */
    private const ASSUMED_SECONDS = 60;

    public function __construct(private readonly Config $config)
    {
    }

    /** Whether the host has told us what its real budget is. */
    public function isDeclared(): bool
    {
        return $this->declaredSeconds() > 0;
    }

    /**
     * The largest archive this machine is believed able to finish, in bytes.
     *
     * ⚠️ A THROUGHPUT RATHER THAN A MEASUREMENT. Reading a hundred objects from a remote store
     * is not something whose speed can be known before doing it, and measuring it once would
     * bake in whatever the network was doing that afternoon. A stated figure is honest about
     * being an assumption, and a host who finds it wrong changes one line.
     */
    public function ceiling(): int
    {
        $seconds = $this->isDeclared() ? $this->declaredSeconds() : self::ASSUMED_SECONDS;
        $throughput = max(1, (int) $this->config->get('mediahub.archives.throughput', 10 * 1024 * 1024));

        return $seconds * $throughput;
    }

    /**
     * What the archive may weigh, all things considered.
     *
     * ⚠️ THE SMALLER OF THE TWO, AND `max_bytes` OF ZERO IS NOT INFINITY HERE. "No limit" in the
     * configuration means "the package imposes none of its own"; it has never meant that the
     * machine can send anything, and reading it that way is what lets a two-hour archive start.
     */
    public function effectiveCeiling(): int
    {
        $configured = (int) $this->config->get('mediahub.archives.max_bytes', 0);
        $deliverable = $this->ceiling();

        return $configured > 0 ? min($configured, $deliverable) : $deliverable;
    }

    private function declaredSeconds(): int
    {
        return max(0, (int) $this->config->get('mediahub.archives.time_budget', 0));
    }
}
