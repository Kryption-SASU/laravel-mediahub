<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Support\ArchiveProgress;

/**
 * HOW FAR THE ARCHIVE HAS GOT — asked from outside the request that is writing it.
 *
 * ⚠️ IT HAS TO BE A SECOND REQUEST, because the first one is busy. The process streaming the ZIP
 * is the only one that knows, and it will not be free to say so until it has finished — at which
 * point nobody needs telling. What it can do is leave the number somewhere both can reach.
 *
 * ⚠️ AND "I HAVE NEVER HEARD OF IT" IS AN ANSWER, NOT AN ERROR. A page can ask before the
 * archive request has been received, and it can ask about one whose record has expired. Neither
 * is a fault, and answering 404 would have the page treat a download that is about to start as
 * one that failed. `known` says which of the two it is; the numbers say the rest.
 */
final class ArchiveProgressController
{
    public function __invoke(string $ticket, ArchiveProgress $progress): JsonResponse
    {
        $found = $progress->read($ticket);

        return new JsonResponse([
            'data' => [
                'known' => $found !== null,
                'total' => (int) ($found['total'] ?? 0),
                'written' => (int) ($found['written'] ?? 0),
                'done' => (bool) ($found['done'] ?? false),
            ],
        ]);
    }
}
