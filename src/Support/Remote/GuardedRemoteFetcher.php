<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Remote;

use Kryption\MediaHub\Contracts\RemoteFetcher;
use Kryption\MediaHub\Exceptions\OperationRejected;

/**
 * THE DEFAULT WAY TO BRING A FILE BACK, AND THE FOUR THINGS THAT MAKE IT SAFE.
 *
 * ⚠️ REDIRECTS ARE FOLLOWED BY HAND, ONE AT A TIME. Letting the HTTP client follow them is the
 * most common way a checked URL still ends up inside the network: `https://example.com/pic` is
 * public, answers `302`, and points at `169.254.169.254`. Every hop goes back through the guard
 * — including the last one, which is the one people forget.
 *
 * ⚠️ THE CONNECTION IS PINNED TO THE ADDRESS THAT WAS CHECKED. Resolving a name twice is the
 * whole DNS rebinding attack: the first answer passes the guard, the second is `127.0.0.1`, and
 * an attacker's name server decides which is which. The transport receives the address, not
 * only the name.
 *
 * ⚠️ THE RESPONSE IS CAPPED IN BYTES AND IN TIME. A URL that answers slowly and forever, or
 * hands back fifty gigabytes, does not need to reach anything private to take a server down.
 *
 * ⚠️ AND NOTHING HERE INSPECTS THE CONTENT. What comes back goes through the same validation as
 * an upload — on the real bytes, not on a `Content-Type` header a remote server chose.
 */
final class GuardedRemoteFetcher implements RemoteFetcher
{
    /**
     * @param  callable(RemoteAddress, int): array{status: int, location: ?string, stream: mixed}  $transport
     */
    public function __construct(
        private readonly AddressGuard $guard,
        private $transport,
        private readonly int $maxBytes = 33_554_432,
        private readonly int $maxRedirects = 3,
    ) {
    }

    public function fetch(string $url): string
    {
        $seen = 0;

        while (true) {
            $address = $this->guard->inspect($url);

            $answer = ($this->transport)($address, $this->maxBytes);

            $status = (int) $answer['status'];
            $location = $answer['location'] ?? null;

            if ($status >= 300 && $status < 400) {
                if ($location === null || $location === '') {
                    throw OperationRejected::because('remote_unreachable', 'That address could not be read.');
                }

                if (++$seen > $this->maxRedirects) {
                    throw OperationRejected::because('remote_too_many_redirects', 'That address redirects too often.');
                }

                /*
                 * ⚠️ A RELATIVE `Location` IS RESOLVED AGAINST THE HOP IT CAME FROM, and then
                 * checked like any other. Treating it as an opaque URL would send `/../` and
                 * friends to the guard as a hostname, and the refusal would be for the wrong
                 * reason — or, worse, would not happen.
                 */
                $url = $this->resolve($address, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw OperationRejected::because('remote_unreachable', 'That address could not be read.');
            }

            return $this->capture($answer['stream']);
        }
    }

    /**
     * ⚠️ WRITTEN TO DISK WHILE IT ARRIVES, NEVER HELD IN MEMORY. A remote file is the one input
     * whose size nobody controls: reading it into a string means a server that a stranger can
     * make run out of memory from the outside.
     */
    private function capture(mixed $stream): string
    {
        if (! is_resource($stream)) {
            throw OperationRejected::because('remote_unreachable', 'That address could not be read.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'mediahub-remote');
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw OperationRejected::because('remote_unreachable', 'That address could not be read.');
        }

        $written = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($chunk === false) {
                break;
            }

            $written += strlen($chunk);

            /*
             * ⚠️ THE CAP IS CHECKED WHILE WRITING, NOT AFTERWARDS. A `Content-Length` is a claim
             * by the other side, and checking the file once it is complete means the disk has
             * already taken everything it was sent.
             */
            if ($written > $this->maxBytes) {
                fclose($handle);
                @unlink($path);

                throw OperationRejected::because('remote_too_large', 'That file is too large.');
            }

            fwrite($handle, $chunk);
        }

        fclose($handle);

        if ($written === 0) {
            @unlink($path);

            throw OperationRejected::because('remote_empty', 'That address answered with nothing.');
        }

        return $path;
    }

    private function resolve(RemoteAddress $from, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $location;
        }

        $base = $from->scheme.'://'.$from->host.($this->isDefaultPort($from) ? '' : ':'.$from->port);

        return $base.'/'.ltrim($location, '/');
    }

    private function isDefaultPort(RemoteAddress $address): bool
    {
        return ($address->scheme === 'https' && $address->port === 443)
            || ($address->scheme === 'http' && $address->port === 80);
    }
}
