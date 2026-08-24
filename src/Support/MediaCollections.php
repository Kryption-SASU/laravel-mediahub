<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\ValueObjects\MediaCollection;

/**
 * WHERE A HOST MODEL DECLARES WHAT IT ACCEPTS.
 *
 * ⚠️ IT IS PASSED AS AN ARGUMENT, NOT CALLED ON `$this`, and that is a deliberate difference
 * from what the ecosystem usually does. A registrar handed in can be built, filled and read
 * without the model being involved: a test doubles it, a screen inspects it, and a command can
 * list what a model accepts without constructing one. Methods called on `$this` make every one
 * of those go through an instance for no reason.
 *
 * ⚠️ AND DECLARING IS OPTIONAL. A model that registers nothing can still attach media — under
 * `default`, with no constraint. The package starts without configuration here as everywhere
 * else.
 */
final class MediaCollections
{
    /** @var array<string, MediaCollection> */
    private array $collections = [];

    /**
     * ⚠️ ADDING THE SAME NAME TWICE RETURNS THE SAME DEFINITION rather than replacing it. A
     * model that registers a collection and a parent class that registers the same one would
     * otherwise silently lose one of the two, depending on the order they ran in — and that
     * order is not something anyone should have to reason about.
     */
    public function add(string $name): MediaCollection
    {
        return $this->collections[$name] ??= new MediaCollection($name);
    }

    public function has(string $name): bool
    {
        return isset($this->collections[$name]);
    }

    public function get(string $name): ?MediaCollection
    {
        return $this->collections[$name] ?? null;
    }

    /** @return array<string, MediaCollection> */
    public function all(): array
    {
        return $this->collections;
    }
}
