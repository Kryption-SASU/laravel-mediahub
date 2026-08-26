<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

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
 * ⚠️ AND NOT EVERY CACHE IS SHARED. `array` and `null` live and die inside one request, so the
 * question would always be answered "never heard of it" and no bar would ever appear. That is a
 * degradation rather than a breakage — the page falls back to knowing only that the answer has
 * begun — but a silent one, which is why {@see \Kryption\MediaHub\Actions\DiagnoseSetup} says so
 * out loud rather than leaving somebody to wonder where their percentage went.
 *
 * ⚠️ AND THE STORE CAN BE NAMED, because "the application's cache" is not always the right one.
 * A host whose default is `array` in one environment, or who would rather keep a hot key out of
 * a database-backed cache, points `mediahub.archives.progress_store` at another one.
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

    /**
     * ⚠️ AND NOT MORE OFTEN THAN THIS EITHER, WHICH IS THE FLOOR THAT MATTERS ON A SLOW STORE.
     * A megabyte is nothing on a fast disk: reading at 300 MB/s, the byte rule alone asks the
     * cache to write three hundred times a second, and on a database-backed store that is three
     * hundred statements a second for a number nobody can read that fast. Four times a second is
     * smooth to a human eye and invisible to a server.
     */
    private const REPORT_EVERY_SECONDS = 0.25;

    /** @var array<string, array{written: int, published: int, at: float}> */
    private array $counted = [];

    private ?Cache $store = null;

    /**
     * ⚠️ THE FLOOR IS INJECTABLE BECAUSE A BENCH CANNOT WAIT FOR A CLOCK RELIABLY. Written with a
     * sleep, the test asserting that a fast read is held back depends on the machine finishing
     * two statements inside a quarter of a second — true almost always, and the "almost" is a
     * suite that goes red on a busy afternoon for no reason anybody can reproduce. Naming the
     * floor makes both sides of the rule exact.
     */
    public function __construct(
        private readonly CacheFactory $caches,
        private readonly Config $config,
        private readonly float $everySeconds = self::REPORT_EVERY_SECONDS,
    ) {
    }

    /**
     * WHETHER TWO REQUESTS CAN ACTUALLY MEET IN THIS CACHE.
     *
     * ⚠️ ASKED OF THE STORE, NOT OF THE CONFIGURATION. A name in a file says what was intended;
     * what is running is what decides whether a second request will ever see this number. `array`
     * and `null` answer no — both are perfectly ordinary choices, and neither survives the end of
     * the request that wrote to it.
     */
    public function isShared(): bool
    {
        $store = $this->cache()->getStore();

        return ! ($store instanceof ArrayStore || $store instanceof NullStore);
    }

    /**
     * What to call the store on screen.
     *
     * ⚠️ THE CLASS RATHER THAN THE CONFIGURED NAME. A host reading "your cache is `array`" when
     * they configured `redis` has learnt something; one reading back their own setting has
     * learnt nothing, and would go on believing the file they edited is what is running.
     */
    public function name(): string
    {
        $parts = explode('\\', $this->cache()->getStore()::class);
        $last = (string) end($parts);

        return strtolower((string) preg_replace('/Store$/', '', $last));
    }

    /** ⚠️ RESOLVED ONCE AND LATE: naming a store that does not exist must fail where it is used. */
    private function cache(): Cache
    {
        $named = $this->config->get('mediahub.archives.progress_store');

        return $this->store ??= $this->caches->store(is_string($named) && $named !== '' ? $named : null);
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

        $this->counted[$ticket] = ['written' => 0, 'published' => 0, 'at' => microtime(true)];

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

        /*
         * ⚠️ AND THE CLOCK HAS ITS SAY TOO, WHICH IS THE FLOOR THAT MATTERS ON A SLOW STORE. A
         * megabyte is nothing on a fast disk: reading at 300 MB/s, the byte rule alone asks the
         * cache to write three hundred times a second — three hundred statements a second on a
         * database-backed store, for a number no eye can follow at that rate.
         */
        $now = microtime(true);

        if ($now - $this->counted[$ticket]['at'] < $this->everySeconds) {
            return;
        }

        $this->counted[$ticket]['published'] = $written;
        $this->counted[$ticket]['at'] = $now;

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

        $found = $this->cache()->get(self::KEY.$ticket);

        return is_array($found) ? $found : null;
    }

    /** ⚠️ TRUSTS ITS TICKET, because nothing reaches it without having been through `start`. */
    private function publish(string $ticket, int $written, int $total, bool $done): void
    {
        $this->cache()->put(
            self::KEY.$ticket,
            ['total' => $total, 'written' => $written, 'done' => $done],
            self::TTL_SECONDS,
        );
    }
}
