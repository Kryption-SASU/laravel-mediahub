<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions;

use RuntimeException;
use Kryption\MediaHub\Exceptions\Concerns\RendersAsJson;

/**
 * THE REQUESTED ITEM DOES NOT EXIST — or does not exist FOR WHOEVER IS ASKING.
 *
 * ⚠️ THE TWO CASES ARE SAID THE SAME WAY, AND THAT IS DELIBERATE. Answering "it exists but it
 * is not yours" answers the question "does this file exist somewhere else?": that is an
 * enumeration oracle, handed free of charge to anyone trying identifiers.
 *
 * ⚠️ AND THIS IS WHERE THE SCOPING LEAK IS CLOSED. The module this package replaces worked on
 * the identifiers received from the client, as they were: a contributor of organisation A
 * deleted a file of organisation B by posting its identifier. Here, resolution goes through the
 * model, therefore through the global scope: a foreign identifier does not resolve, and nothing
 * runs.
 */
final class ItemNotFound extends RuntimeException
{
    use RendersAsJson;

    private function __construct(public readonly string $kind, public readonly string $key)
    {
        parent::__construct('item_not_found');
    }

    public static function media(string $key): self
    {
        return new self('media', $key);
    }

    public static function folder(string $key): self
    {
        return new self('folder', $key);
    }

    protected function status(): int
    {
        return 404;
    }

    protected function reasonKey(): string
    {
        return 'item_not_found';
    }
}
