<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kryption\MediaHub\Actions\BrowseMedia;
use Kryption\MediaHub\Exceptions\ItemNotFound;
use Kryption\MediaHub\Http\Resources\FolderResource;
use Kryption\MediaHub\Http\Resources\MediaResource;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\ValueObjects\BrowseQuery;

/**
 * WHAT IS INSIDE A FOLDER — files, subfolders, and the trail back.
 *
 * ⚠️ THE REQUESTED FOLDER IS RESOLVED THROUGH THE MODEL, THEREFORE THROUGH THE GLOBAL SCOPE. A
 * folder identifier belonging to somebody else does not resolve: we return 404, not the
 * content. The original module's listing was scoped, but by a clause written by hand in each
 * method — three times, and it was missing elsewhere.
 *
 * ⚠️ AND "NO FOLDER" MEANS THE ROOT, NOT "EVERYWHERE". Confusing the two displays the entire
 * library flattened as soon as the screen opens.
 */
final class BrowseController
{
    public function __construct(
        private readonly BrowseMedia $browse,
        private readonly FolderTree $tree,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $folder = $this->folder($request);

        $query = BrowseQuery::fromInput($request->all(), $folder, rootOnly: true);

        /*
         * ⚠️ THE FOLDERS HALF USED TO IGNORE THE TRASH ENTIRELY. Only the media were asked for
         * with `onlyTrashed()`; the folders came from the plain query, which the soft-delete
         * scope filters — so the trash showed the LIVE folders and hid its own. Reported from a
         * real screen on 25/08/2026: a branch thrown away could not be found, let alone put back.
         *
         * ⚠️ AND AT THE TOP OF THE TRASH, WHAT IS LISTED IS WHAT WAS THROWN AWAY — not what sits
         * at the root of the library. A folder whose parent is in the trash too is reached by
         * walking into that parent, exactly as in the library; one whose parent is still alive
         * has nowhere else to appear, so it belongs at the top.
         *
         * ⚠️ THE RULE IS EXPRESSED AGAINST A LIST OF KEYS RATHER THAN WITH `whereHas('parent')`,
         * and that is not a preference. On a self-referencing model the relation subquery is
         * aliased, but the soft-delete scope qualifies its column against the OUTER table:
         *
         *     exists (select * from media_folders as laravel_reserved_0
         *             where laravel_reserved_0.id = media_folders.parent_id
         *               and media_folders.deleted_at is null)
         *
         * Every row this filter looks at is trashed, so that last condition is false for all of
         * them and the whole `exists` never matches. It returns an empty trash, with no error.
         *
         * ⚠️ AND "AT THE ROOT" HAS TO BE SPELLED OUT, RATHER THAN LEFT TO THE `NOT IN`. On a
         * schema that writes the root as NULL — which is this package's own — `parent_id NOT IN
         * (…)` is UNKNOWN for those rows, not TRUE, so SQL discards every one of them: the top
         * of the trash listed nothing at all, and a folder thrown away at the root was gone from
         * the only screen meant to show it. The preset that writes the root as 0 hid this for as
         * long as it was the only one under test, because 0 is a value and a value compares.
         */
        $inTheTrash = $query->trashed
            ? MediaFolder::onlyTrashed()->pluck((new MediaFolder())->getKeyName())->all()
            : [];

        $subfolders = MediaFolder::query()
            ->with('parent')
            ->when(
                $query->trashed,
                fn ($builder) => $builder
                    ->onlyTrashed()
                    ->when(
                        $folder === null,
                        fn ($roots) => $roots->where(
                            fn ($nested) => $nested
                                ->atParent('parent_id', null)
                                ->orWhereNotIn(MediaFolder::column('parent_id'), $inTheTrash),
                        ),
                        fn ($inside) => $inside->atParent('parent_id', $folder?->getKey()),
                    ),
                fn ($builder) => $builder->atParent('parent_id', $folder?->getKey()),
            )
            ->orderBy(MediaFolder::column('name'))
            ->get();

        $page = ($this->browse)($query);

        return new JsonResponse([
            'data' => [
                'folder' => $folder === null ? null : new FolderResource($folder),
                'breadcrumbs' => $folder === null
                    ? []
                    : FolderResource::collection($this->tree->breadcrumbs($folder)),
                'folders' => FolderResource::collection($subfolders),
                'media' => MediaResource::collection($page->items()),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * ⚠️ THE FOLDER IS RESOLVED HERE RATHER THAN BY THE ROUTE, because it is OPTIONAL. An
     * optional route parameter would force either two routes or accepting an empty string as an
     * identifier; here, its absence has an explicit meaning — the root.
     */
    private function folder(Request $request): ?MediaFolder
    {
        $key = $request->query('folder');

        if ($key === null || $key === '') {
            return null;
        }

        /*
         * ⚠️ A TRASHED FOLDER CAN BE OPENED — from the trash, and only from there. Resolved with
         * the plain query it answered "no such folder", so a branch in the trash could be seen at
         * its root and never walked into: its nesting, and everything inside, was unreachable.
         */
        $folder = MediaFolder::query()
            ->when(
                $request->boolean('trashed'),
                static fn ($builder) => $builder->withTrashed(),
            )
            ->with('parent')
            ->where((new MediaFolder())->getRouteKeyName(), (string) $key)
            ->first();

        if ($folder === null) {
            throw ItemNotFound::folder((string) $key);
        }

        return $folder;
    }
}
