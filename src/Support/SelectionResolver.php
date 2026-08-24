<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Support\Collection;
use Kryption\MediaHub\Exceptions\ItemNotFound;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\ValueObjects\ItemSelection;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * THE STEP FROM KEYS TO OBJECTS — and the only place where a batch can be refused whole.
 *
 * ⚠️ A SINGLE UNKNOWN KEY BRINGS THE WHOLE BATCH DOWN. The rule comes from a real case: the
 * module this package replaces authorised nine items out of ten and executed anyway. The tenth
 * belonged to another customer — refused by the check, deleted by the action. Authorising
 * partially is authorising nothing at all.
 *
 * ⚠️ AND THE REFUSAL HAPPENS BEFORE THE SLIGHTEST WRITE, not halfway through the loop. An
 * action that checks item by item while acting as it goes leaves a half-executed batch behind
 * it — that is, a state nobody asked for.
 *
 * ⚠️ "NOT FOUND" AND "NOT YOURS" ARE SAID THE SAME WAY. The global scope applies here as
 * everywhere; what is outside the scope does not resolve, and nothing in the response tells the
 * two cases apart.
 */
final class SelectionResolver
{
    public function resolve(ItemSelection $selection, bool $withTrashed = false): ResolvedItems
    {
        return new ResolvedItems(
            $this->media($selection->media, $withTrashed),
            $this->folders($selection->folders, $withTrashed),
        );
    }

    /**
     * @param  array<int, string>  $keys
     * @return Collection<int, Media>
     */
    private function media(array $keys, bool $withTrashed): Collection
    {
        $keys = $this->normalise($keys);

        if ($keys === []) {
            return new Collection();
        }

        $column = (new Media())->getRouteKeyName();

        $found = Media::query()
            ->when($withTrashed, static fn ($query) => $query->withTrashed())
            ->whereIn($column, $keys)
            ->get();

        $this->requireAll($keys, $found->pluck($column)->all(), 'media');

        return $found;
    }

    /**
     * @param  array<int, string>  $keys
     * @return Collection<int, MediaFolder>
     */
    private function folders(array $keys, bool $withTrashed): Collection
    {
        $keys = $this->normalise($keys);

        if ($keys === []) {
            return new Collection();
        }

        $column = (new MediaFolder())->getRouteKeyName();

        $found = MediaFolder::query()
            ->when($withTrashed, static fn ($query) => $query->withTrashed())
            ->whereIn($column, $keys)
            ->get();

        $this->requireAll($keys, $found->pluck($column)->all(), 'folder');

        return $found;
    }

    /**
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function normalise(array $requested): array
    {
        $clean = [];

        foreach ($requested as $key) {
            $key = trim((string) $key);

            if ($key !== '' && ! in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return $clean;
    }

    /**
     * @param  array<int, string>  $requested
     * @param  array<int, mixed>  $found
     */
    private function requireAll(array $requested, array $found, string $kind): void
    {
        $found = array_map(static fn ($key): string => (string) $key, $found);

        foreach ($requested as $key) {
            if (! in_array($key, $found, true)) {
                throw $kind === 'media' ? ItemNotFound::media($key) : ItemNotFound::folder($key);
            }
        }
    }
}
