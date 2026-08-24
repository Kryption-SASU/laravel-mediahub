<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * THE TREE — and the only place where the materialised path is built.
 *
 * ⚠️ `path` IS DERIVED, THEREFORE IT CAN LIE. It is a cache: it saves walking back up the tree
 * on every display, but it is only true for as long as somebody keeps it up to date. A move
 * left half finished leaves it pointing at the old parent, and nothing reports it any more.
 * Hence this class: refreshing the descendants lives in ONE place, not in every action that
 * moves or renames.
 *
 * ⚠️ AND NOTHING IS DEDUCED FROM `path` BY STRING MANIPULATION. The truth is `parent_id`;
 * `path` is what we display of it. The original module did the opposite and its
 * `parentFolder()` relation pointed at a column that did not exist — the tree was wrong and
 * nothing fell over.
 */
final class FolderTree
{
    /**
     * ⚠️ THE CLIMB IS BOUNDED. A corrupted tree — a folder that became its own ancestor through
     * a concurrent write or a data migration — would spin this loop until memory ran out. An
     * absurd depth is a data defect, not a reason to kill the process.
     */
    public const MAX_DEPTH = 64;

    /**
     * THE BREADCRUMB TRAIL, from the root down to this folder inclusive.
     *
     * @return Collection<int, MediaFolder>
     */
    public function breadcrumbs(MediaFolder $folder): Collection
    {
        $trail = [];
        $current = $folder;
        $guard = 0;

        while ($current !== null && $guard++ < self::MAX_DEPTH) {
            $trail[] = $current;
            $current = $current->parent_id === null ? null : $this->parent($current);
        }

        return new Collection(array_reverse($trail));
    }

    /**
     * IS THIS FOLDER A DESCENDANT OF THAT ONE?
     *
     * ⚠️ WE CLIMB, WE DO NOT DESCEND. Descending costs the whole tree; climbing costs the
     * depth, which is counted in single digits. And it is the same question.
     */
    public function isDescendant(MediaFolder $candidate, MediaFolder $ancestor): bool
    {
        $current = $candidate;
        $guard = 0;

        while ($current !== null && $guard++ < self::MAX_DEPTH) {
            if ($current->getKey() === $ancestor->getKey()) {
                return true;
            }

            $current = $current->parent_id === null ? null : $this->parent($current);
        }

        return false;
    }

    /**
     * A SLUG FREE AMONG THE SIBLINGS.
     *
     * ⚠️ TRASHED SIBLINGS COUNT. They can be restored: ignoring their slug amounts to setting
     * up two folders with the same name in the same place, and the conflict will only surface
     * at restore time — that is, long after its cause.
     */
    public function uniqueSlug(string $name, ?int $parentId, ?int $exceptId = null): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'folder' : $base;

        $taken = MediaFolder::withTrashed()
            ->atParent('parent_id', $parentId)
            ->when($exceptId !== null, static fn ($query) => $query->whereKeyNot($exceptId))
            ->pluck(MediaFolder::column('slug'))
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $suffix = 2;

        while (in_array($base.'-'.$suffix, $taken, true)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    /** A folder's path and depth, derived from its parent. */
    public function materialize(MediaFolder $folder, ?MediaFolder $parent): void
    {
        $folder->path = $parent === null
            ? (string) $folder->slug
            : rtrim((string) $parent->path, '/').'/'.$folder->slug;

        $folder->depth = $parent === null ? 0 : ((int) $parent->depth) + 1;
    }

    /**
     * REWRITES THE PATH AND DEPTH OF EVERY DESCENDANT.
     *
     * ⚠️ TRASHED DESCENDANTS ARE REWRITTEN TOO. A folder restored later would otherwise carry
     * the path from before the move, and it would appear somewhere it is not.
     *
     * ⚠️ AND WE DO IT IN PHP, NOT IN SQL. Rewriting a string prefix in the database calls for
     * a raw expression whose semantics change from one engine to the next — and a folder tree
     * is counted in hundreds of rows, never in millions.
     *
     * @return int the number of descendants rewritten
     */
    public function refreshSubtree(MediaFolder $folder): int
    {
        $rewritten = 0;
        $queue = [$folder];
        $guard = 0;

        while ($queue !== [] && $guard++ < self::MAX_DEPTH) {
            $parents = $queue;
            $queue = [];

            foreach ($parents as $parent) {
                $children = MediaFolder::withTrashed()
                    ->atParent('parent_id', $parent->getKey())
                    ->get();

                foreach ($children as $child) {
                    $this->materialize($child, $parent);
                    $child->save();
                    $rewritten++;
                    $queue[] = $child;
                }
            }
        }

        return $rewritten;
    }

    /**
     * ⚠️ THE PARENT IS READ WITH THE TRASH. A trashed folder keeps its children: without
     * `withTrashed()`, the climb would stop dead at the first deleted ancestor, and the
     * recomputed path would lose its head.
     */
    private function parent(MediaFolder $folder): ?MediaFolder
    {
        return MediaFolder::withTrashed()->find($folder->parent_id);
    }

    /**
     * THE KEYS OF A FOLDER AND OF EVERY DESCENDANT.
     *
     * ⚠️ THE TRASH IS PART OF IT. A permanent deletion that forgot the descendants already in
     * the trash would leave folders whose parent no longer exists: invisible in the tree,
     * present in the table, and attached to bytes nothing claims any more. That is how one
     * real case came to hold 6,302 orphans.
     *
     * @return array<int, mixed>
     */
    public function subtreeKeys(MediaFolder $folder): array
    {
        /*
         * ⚠️ NO KEY RETURNED IS NULL, and that is what makes translating the root unnecessary
         * here. Such a translation was written and then removed: no mutation woke it up,
         * because the descent always starts from an existing folder. A guard no test can catch
         * out is not a guard, it is a line to maintain.
         */
        $keys = [$folder->getKey()];
        $level = [$folder->getKey()];
        $guard = 0;

        while ($level !== [] && $guard++ < self::MAX_DEPTH) {
            $level = MediaFolder::withTrashed()
                ->whereIn(MediaFolder::column('parent_id'), $level)
                ->pluck((new MediaFolder())->getKeyName())
                ->all();

            foreach ($level as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
