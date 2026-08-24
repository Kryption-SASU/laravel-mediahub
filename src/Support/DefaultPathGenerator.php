<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\PathGenerator;

/**
 * WHERE A FILE LANDS — and the package does not decide that.
 *
 * ⚠️ THIS CLASS FILES NOTHING: IT SANITISES. The folder is GIVEN to it by the caller; it cleans
 * it, it does not rewrite it. That property is what makes the package worth having, and it
 * deserves saying: how media are organised belongs to the trade of whoever installs it. An
 * agency files by client and campaign, an intranet by department, a messaging product by
 * thread. Imposing a tree would amount to imposing a trade.
 *
 * ⚠️ WHAT IS GENERIC, ON THE OTHER HAND, STAYS HERE, because everyone needs it and nobody wants
 * to write it again:
 *
 *   - a segment NEVER climbs out of a folder — this is where, and nowhere else, path traversal
 *     is closed, because this is where a path is built;
 *   - a derivative is filed next to its original, and its name is derived from it.
 *
 * ⚠️ AND THE PATH IS DECIDED ON WRITE, THEN RECORDED — never recomputed on read. Otherwise
 * renaming a folder would become a file migration, with its own window for breakage.
 *
 * A host that wants more — families, a root per client, a tree by date — implements
 * `PathGenerator` its own way. That is the door provided for it.
 */
final class DefaultPathGenerator implements PathGenerator
{
    /**
     * @param  string  $prefix  a shared leading folder, if the host wants one
     */
    public function __construct(private readonly string $prefix = '')
    {
    }

    /**
     * @param  array{directory?: string}  $context  what the caller decided
     */
    public function directory(array $context): string
    {
        $segments = array_merge(
            $this->segments($this->prefix),
            $this->segments((string) ($context['directory'] ?? ''))
        );

        return $segments === [] ? '' : implode('/', $segments).'/';
    }

    /**
     * THE PATH OF A DERIVATIVE — next to its original, suffixed before the extension.
     *
     * ⚠️ THIS IS NOT AN AESTHETIC PREFERENCE. Some readers derive this path by string
     * manipulation from the original's: filing them elsewhere would break those readers, and
     * they are often out of our sight.
     */
    public function conversion(string $mediaPath, string $conversion, ?string $extension = null): string
    {
        $suffix = trim($conversion, '-');

        if ($suffix === '') {
            return $mediaPath;
        }

        $current = pathinfo($mediaPath, PATHINFO_EXTENSION);

        /*
         * ⚠️ THE REQUESTED EXTENSION IS REDUCED TO LETTERS AND DIGITS. Today it comes from an
         * internal table, but THIS is where paths are built: the day it comes from somewhere
         * else, directory traversal will already be closed.
         */
        $wanted = $extension === null ? '' : (string) preg_replace('/[^A-Za-z0-9]/', '', $extension);

        $final = $wanted !== '' ? strtolower($wanted) : $current;

        $base = $current === '' ? $mediaPath : substr($mediaPath, 0, -(strlen($current) + 1));

        return $final === '' ? $base.'-'.$suffix : $base.'-'.$suffix.'.'.$final;
    }

    /**
     * @return array<int, string>
     */
    private function segments(string $path): array
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = $this->sanitize($segment);

            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * ⚠️ A SEGMENT NEVER CLIMBS OUT OF A FOLDER. No `..`, no separator, no null byte.
     *
     * ⚠️ AND WE SANITISE WITHOUT TRANSFORMING. Dangerous characters disappear; the rest is left
     * as it is — accents and capitals included. Normalising beyond what is necessary would
     * impose a taste, and would make the path the caller asked for unpredictable.
     */
    private function sanitize(string $segment): string
    {
        $segment = str_replace(["\0", '\\', '/'], '', $segment);

        return trim(trim($segment), '.');
    }
}
