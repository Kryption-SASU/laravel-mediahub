<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * WHAT A SELECTION ACTUALLY CARRIES — before anything is done to it.
 *
 * ⚠️ A FOLDER IS NEVER JUST A FOLDER. `TrashItems` and `ForceDeleteItems` take the whole subtree,
 * descendants included, because a folder deleted while its files stay visible leaves rows
 * attached to an absent parent. That is the right behaviour and it is not going to change — but
 * it means "delete 1 folder" can mean "delete 1 folder and four hundred files", and until now
 * nothing could say so before the fact.
 *
 * ⚠️ IT COUNTS WHAT THE ACTION WOULD TOUCH, NOT WHAT WAS TICKED. The numbers are the ones the
 * operation will reach: the media in every subtree plus the media chosen directly, and every
 * folder in every subtree. A confirmation built on the ticked count would reassure somebody
 * about a figure the action never uses.
 *
 * ⚠️ AND IT ANSWERS FOR A RESOLVED SELECTION, so it goes through the scope like everything else.
 * Counting from raw keys would report files the caller cannot see and, worse, would report them
 * accurately — telling somebody how much of another tenant's library sits under a folder.
 */
final class CountSelection
{
    public function __construct(private readonly FolderTree $tree)
    {
    }

    /**
     * @return array{media: int, folders: int}
     */
    public function __invoke(ResolvedItems $items, bool $withTrashed = false): array
    {
        $folders = [];

        foreach ($items->folders as $folder) {
            foreach ($this->tree->subtreeKeys($folder) as $key) {
                $folders[$key] = true;
            }
        }

        $direct = $items->media->map(static fn ($model) => $model->getKey())->all();

        return [
            'media' => $this->media($folders, $direct, $withTrashed),
            'folders' => count($folders),
        ];
    }

    /**
     * @param  array<array-key, true>  $folders
     * @param  list<mixed>  $direct
     */
    private function media(array $folders, array $direct, bool $withTrashed): int
    {
        if ($folders === [] && $direct === []) {
            return 0;
        }

        $query = $withTrashed ? Media::withTrashed() : Media::query();

        /*
         * ⚠️ ONE QUERY, AND THE TWO SETS ARE UNIONED RATHER THAN ADDED. A file ticked directly
         * that also sits inside a ticked folder would otherwise be counted twice, and the
         * confirmation would name more files than the action goes on to touch.
         */
        return $query
            ->where(function ($inner) use ($folders, $direct): void {
                if ($folders !== []) {
                    $inner->orWhereIn(Media::column('folder_id'), array_keys($folders));
                }

                if ($direct !== []) {
                    /* ⚠️ THE KEY COLUMN IS ASKED FOR, NOT ASSUMED. On an adopted schema it is
                     * whatever the host calls it, and `orWhereKey()` does not exist on a
                     * builder in any case — only its `where` counterpart does. */
                    $inner->orWhereIn((new Media())->getKeyName(), $direct);
                }
            })
            ->count();
    }
}
