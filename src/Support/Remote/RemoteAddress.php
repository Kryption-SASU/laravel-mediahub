<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Remote;

/**
 * A URL THAT HAS BEEN CHECKED, AND THE ADDRESS IT WAS CHECKED AGAINST.
 *
 * ⚠️ THE POINT OF THIS TYPE IS THAT THE IP TRAVELS WITH THE URL. Checking a hostname and then
 * letting the HTTP client resolve it again is the whole DNS rebinding attack: the first answer
 * is a public address that passes, the second is `127.0.0.1`, and the request goes wherever the
 * attacker's name server decides between the two. Carrying the address that was validated is
 * what lets the connection be pinned to it.
 */
final class RemoteAddress
{
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        /** The address the connection must be made to, and no other. */
        public readonly string $ip,
    ) {
    }
}
