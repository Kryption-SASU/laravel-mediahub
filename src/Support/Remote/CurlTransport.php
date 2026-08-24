<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Remote;

use Kryption\MediaHub\Exceptions\OperationRejected;

/**
 * ONE REQUEST, TO ONE ADDRESS, AND NOTHING ELSE.
 *
 * ⚠️ THIS CLASS IS DELIBERATELY THIN, because it is the one piece that cannot be tested without
 * a network. Everything that decides anything — which addresses are allowed, which redirects are
 * followed, when to stop reading — lives in classes that are tested exhaustively without one.
 * What is left here is the call itself.
 *
 * ⚠️ `CURLOPT_RESOLVE` IS WHAT PINS THE CONNECTION. It tells cURL "for this name and port, use
 * this address" — so the name is not looked up again between the check and the connection, which
 * is the entire DNS rebinding attack. The `Host` header stays the name, so virtual hosting and
 * certificates still work.
 *
 * ⚠️ AND REDIRECTS ARE NOT FOLLOWED HERE. `CURLOPT_FOLLOWLOCATION` would follow them without
 * asking anybody, which is how a checked URL still ends up inside the network. The caller
 * follows them one at a time, checking each.
 */
final class CurlTransport
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    /**
     * @return array{status: int, location: ?string, stream: mixed}
     */
    public function __invoke(RemoteAddress $address, int $maxBytes): array
    {
        if (! function_exists('curl_init')) {
            throw OperationRejected::because(
                'remote_unsupported',
                'This installation cannot fetch remote files.',
            );
        }

        $stream = fopen('php://temp', 'r+b');
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $address->url,
            CURLOPT_FILE => $stream,
            CURLOPT_HEADER => false,

            /* ⚠️ THE PIN. Without it, cURL resolves the name again and the guard means nothing. */
            CURLOPT_RESOLVE => [$address->host.':'.$address->port.':'.$address->ip],

            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,

            /*
             * ⚠️ CERTIFICATES ARE VERIFIED, AND THE PIN DOES NOT CHANGE THAT. `CURLOPT_RESOLVE`
             * leaves the host name in place precisely so the certificate is still checked
             * against it — turning verification off here would trade one hole for another.
             */
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            /*
             * ⚠️ A SECOND CAP, AT THE TRANSPORT. The caller counts bytes as it writes them; this
             * one refuses a body whose announced length is already past the limit, so nothing is
             * transferred at all where the other side is honest about the size.
             */
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_NOPROGRESS => true,
        ]);

        $performed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);

        $failure = curl_error($handle);

        curl_close($handle);

        if ($performed === false && $status === 0) {
            throw OperationRejected::because(
                'remote_unreachable',
                $failure !== '' ? $failure : 'That address could not be read.',
            );
        }

        rewind($stream);

        return [
            'status' => $status,
            'location' => is_string($location) && $location !== '' ? $location : null,
            'stream' => $stream,
        ];
    }
}
