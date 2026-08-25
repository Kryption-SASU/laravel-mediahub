<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

/**
 * WHAT THIS MACHINE WILL ACTUALLY ALLOW, as opposed to what the configuration permits.
 *
 * ⚠️ A CONFIGURED CEILING IS A PROMISE THE RUNTIME DOES NOT KNOW ABOUT. `uploads.max_size` set
 * to two hundred megabytes on a PHP whose `post_max_size` is eight means every upload above
 * eight is refused before a single line of this package runs — by the interpreter, with an empty
 * body and no reason. The host reads their own configuration, sees two hundred, and reports a
 * broken uploader.
 *
 * ⚠️ THE SHORTHAND IS NOT A NUMBER, AND `(int)` GETS IT WRONG SILENTLY. `(int) '8M'` is 8, not
 * 8388608 — an error of six orders of magnitude that produces a comparison which always passes.
 * Every directive read here goes through the same conversion.
 *
 * ⚠️ AND WHAT CANNOT BE SEEN IS SAID RATHER THAN GUESSED. PHP-FPM's `request_terminate_timeout`
 * and the front-end server's proxy timeout are what really cut a long download, and neither is
 * readable from inside the process. This class reports what it can measure and returns `null`
 * for the rest; the code above it treats `null` as "unknown", never as "unlimited".
 */
final class RuntimeLimits
{
    /**
     * A size directive, in bytes.
     *
     * ⚠️ `null` MEANS UNBOUNDED, AND `0` IS HOW PHP SPELLS IT for the size directives — while
     * `-1` is how it spells it for `memory_limit`. Both are answered the same way here so no
     * caller has to remember which is which.
     */
    public function bytes(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '') {
            return null;
        }

        $value = $this->toBytes($raw);

        return $value <= 0 ? null : $value;
    }

    /** A time directive, in seconds. `null` means unbounded. */
    public function seconds(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '') {
            return null;
        }

        $value = (int) $raw;

        return $value <= 0 ? null : $value;
    }

    public function flag(string $directive): bool
    {
        $raw = strtolower(trim((string) ini_get($directive)));

        return in_array($raw, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * ⚠️ WHETHER A DIRECTIVE CAN STILL BE CHANGED FROM HERE. A host may pin it as
     * `PHP_INI_SYSTEM`, and `ini_set` then returns `false` without a word — so the package would
     * believe it had turned buffering off and stream into memory anyway.
     */
    public function canSet(string $directive): bool
    {
        $before = ini_get($directive);

        if ($before === false) {
            return false;
        }

        $changed = @ini_set($directive, $before);

        return $changed !== false;
    }

    /** ⚠️ `disable_functions` IS COMMON ON SHARED HOSTING, and it is the reason this is asked. */
    public function canCall(string $function): bool
    {
        return function_exists($function)
            && ! in_array(
                $function,
                array_map(
                    static fn (string $one): string => strtolower(trim($one)),
                    explode(',', (string) ini_get('disable_functions')),
                ),
                true,
            );
    }

    /**
     * ⚠️ HOW MUCH ROOM IS LEFT, NOT HOW MUCH THERE IS. A limit of 256 MB with 200 already in use
     * is a 56 MB budget, and a check against the limit would pass on its way to an exhaustion.
     */
    public function memoryLeft(): ?int
    {
        $limit = $this->bytes('memory_limit');

        return $limit === null ? null : max(0, $limit - memory_get_usage(true));
    }

    /**
     * ⚠️ THE SHORTHAND IS SUFFIX-BASED AND CASE-INSENSITIVE, and only the LAST character counts:
     * PHP reads "1G" as a gigabyte and "1GB" as one byte, because it looks at the final letter
     * and finds "B". That is not a mistake worth reproducing, so a trailing "b" is dropped first
     * — a host who wrote "8MB" meant eight megabytes, and their intent is served better than
     * their typo.
     */
    private function toBytes(string $raw): int
    {
        $text = strtolower($raw);

        if (str_ends_with($text, 'b')) {
            $text = substr($text, 0, -1);
        }

        $number = (int) $text;
        $suffix = substr($text, -1);

        return match ($suffix) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
