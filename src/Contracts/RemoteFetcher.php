<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Kryption\MediaHub\Exceptions\OperationRejected;

/**
 * BRINGING A FILE BACK FROM A URL SOMEBODY ELSE CHOSE.
 *
 * ⚠️ THIS IS A CONTRACT BECAUSE THE RIGHT ANSWER DEPENDS ON THE NETWORK IT RUNS IN. An
 * application behind an egress proxy already has a place where this decision is made and
 * audited; one on a private network may want an allow-list and nothing else. The package ships
 * an implementation that is safe on its own, and gets out of the way of a host that has better.
 *
 * ⚠️ AND WHATEVER REPLACES IT INHERITS THE OBLIGATION. Fetching a URL from the server is a
 * request-forgery primitive: the address must be checked, the connection must go to the address
 * that was checked, every redirect must be checked again, and the response must be capped in
 * size and in time. An implementation that skips any of those hands an attacker the inside of
 * the network.
 */
interface RemoteFetcher
{
    /**
     * Fetches the URL and returns the path of a local temporary file.
     *
     * ⚠️ THE CALLER OWNS THE FILE THAT COMES BACK and is responsible for removing it. Deleting
     * it here would mean deleting it before anything could read it.
     *
     * @throws OperationRejected when the address is refused, unreachable, or answers with more
     *                           than the caller is willing to accept
     */
    public function fetch(string $url): string;
}
