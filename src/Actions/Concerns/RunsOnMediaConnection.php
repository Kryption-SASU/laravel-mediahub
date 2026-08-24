<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Kryption\MediaHub\Models\Media;

/**
 * THE TRANSACTION OPENS ON THE MODELS' CONNECTION — not on the default one.
 *
 * ⚠️ THESE ARE NOT THE SAME THING, AND THE DIFFERENCE IS INVISIBLE UNTIL IT IS FATAL. A host
 * putting the library on a second database — a separate read replica, a dedicated schema, a
 * recovery database — would see its actions open a transaction on the DEFAULT connection while
 * the writes go elsewhere. Nothing would fall over: the transaction would protect a database
 * where nothing happens, and the first failure mid-batch would leave half the work committed.
 *
 * ⚠️ AND IF THE TWO MODELS LIVED ON TWO CONNECTIONS, NO TRANSACTION WOULD COVER THEM TOGETHER.
 * That is not a supported configuration; it is said here so nobody believes otherwise.
 */
trait RunsOnMediaConnection
{
    private function connection(ConnectionResolverInterface $connections): ConnectionInterface
    {
        return $connections->connection((new Media())->getConnectionName());
    }
}
