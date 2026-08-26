<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * HOW FAR AN ARCHIVE HAS GOT, PUBLISHED WHERE THE PAGE THAT ASKED FOR IT CAN READ IT.
 *
 * ⚠️ THE BROWSER NEVER TELLS A PAGE THAT A DOWNLOAD HAS FINISHED. There is no event and no API:
 * once the attachment has been taken, the page is blind. So the only honest progress is the one
 * the SERVER can see — the bytes it has read and written — and it has to leave the request in
 * order to be read at all, because the request that knows is the one still streaming.
 *
 * ⚠️ WHICH MEANS A SHARED STORE, NOT A PROPERTY. The request writing the archive and the
 * requests asking about it are different processes: a counter in memory would be read by nobody.
 * The application's cache is where two processes already agree to meet.
 *
 * ⚠️ AND WHAT IT REPORTS IS THE SERVER'S SIDE OF THE TRANSFER. Between the last byte written
 * here and the last byte the browser receives there are network buffers — a few hundred
 * milliseconds, sometimes more on a slow line. This is close, and it is not the same thing;
 * saying so is what keeps the number worth showing.
 *
 * ⚠️ THE TOTAL IS THE BYTES GOING IN, NOT THE ARCHIVE'S SIZE. A ZIP's final size is unknowable
 * before it is written — that is the whole reason it can be streamed at all. What is known is
 * the weight of what is being put into it, which is what the ceiling was checked against, and
 * it is the honest denominator for "how far along".
 */
final class ArchiveProgress
{
    /**
     * ⚠️ THE TICKET IS THE PAGE'S, NOT OURS, and it is checked before it touches a cache key.
     * It arrives in a request body and ends up concatenated into that key: unchecked, it is a
     * way to write wherever the key namespace can reach.
     */
    private const TICKET = '/^[A-Za-z0-9]{8,64}$/';

    private const KEY = 'mediahub:archive:';

    /** Long enough to outlive the download, short enough to be forgotten by itself. */
    private const TTL_SECONDS = 900;

    /**
     * ⚠️ NOT EVERY CHUNK: A STORE IS NOT FREE. Files arrive in blocks of a few kilobytes, so
     * reporting each one would be tens of thousands of round trips for one archive — and nobody
     * can see a bar move a thousandth of a percent. A megabyte is under a second's worth of
     * transfer on any line where a progress bar is worth having.
     */
    private const REPORT_EVERY_BYTES = 1_048_576;

    /** @var array<string, array{written: int, published: int}> */
    private array $counted = [];

    public function __construct(private readonly Cache $cache)
    {
    }

    public static function isTicket(?string $ticket): bool
    {
        return is_string($ticket) && preg_match(self::TICKET, $ticket) === 1;
    }

    /**
     * ⚠️ PUBLISHED BEFORE THE FIRST BYTE, so that a page asking early is told "nothing yet"
     * rather than "no such archive". The difference decides whether it keeps waiting or gives
     * up on a download that is about to start.
     */
    public function start(string $ticket, int $total): void
    {
        /*
         * ⚠️ THE ONLY DOOR IN, SO THE ONLY PLACE THE TICKET IS JUDGED. Nothing else here can
         * publish anything without having been started first, which means one check rather than
         * one per method — and a check repeated four times is a check three of which are never
         * reached, and therefore never known to work.
         */
        if (! self::isTicket($ticket)) {
            return;
        }

        $this->counted[$ticket] = ['written' => 0, 'published' => 0];

        $this->publish($ticket, 0, max(0, $total), false);
    }

    public function advance(string $ticket, int $bytes, int $total): void
    {
        if (! isset($this->counted[$ticket])) {
            return;
        }

        $this->counted[$ticket]['written'] += $bytes;
        $written = $this->counted[$ticket]['written'];

        if ($written - $this->counted[$ticket]['published'] < self::REPORT_EVERY_BYTES) {
            return;
        }

        $this->counted[$ticket]['published'] = $written;

        $this->publish($ticket, $written, $total, false);
    }

    /**
     * ⚠️ THE END IS ANNOUNCED EXPLICITLY RATHER THAN INFERRED FROM THE NUMBERS. Written equals
     * total is not the same statement as finished: a file that could not be read leaves the
     * count short for ever, and an archive whose entries were counted generously would look
     * done before its last file. A page reading "99%" and nothing more would wait for ever.
     */
    public function finish(string $ticket, int $total): void
    {
        if (! isset($this->counted[$ticket])) {
            return;
        }

        $this->publish($ticket, $this->counted[$ticket]['written'], $total, true);

        unset($this->counted[$ticket]);
    }

    /** @return array{total: int, written: int, done: bool}|null */
    public function read(string $ticket): ?array
    {
        if (! self::isTicket($ticket)) {
            return null;
        }

        $found = $this->cache->get(self::KEY.$ticket);

        return is_array($found) ? $found : null;
    }

    /** ⚠️ TRUSTS ITS TICKET, because nothing reaches it without having been through `start`. */
    private function publish(string $ticket, int $written, int $total, bool $done): void
    {
        $this->cache->put(
            self::KEY.$ticket,
            ['total' => $total, 'written' => $written, 'done' => $done],
            self::TTL_SECONDS,
        );
    }
}
