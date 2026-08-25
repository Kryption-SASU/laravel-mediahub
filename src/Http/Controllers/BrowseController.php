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

        $folders = MediaFolder::query()
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
            ->orderBy(MediaFolder::column('name'));

        /*
         * A LEVEL IS FOLDERS AND FILES, AND A PAGE OF IT IS A PAGE OF BOTH.
         *
         * ⚠️ PAGINATING THE MEDIA ALONE PUT EVERY FOLDER ON TOP OF EVERY PAGE. A level with twelve
         * folders showed sixty tiles where it promised forty-eight, the same twelve came back on
         * page two, and "page 2 of 3" counted only half of what was on screen. A folder is one
         * tile; it has to be one item.
         *
         * ⚠️ FOLDERS COME FIRST, ALWAYS, AND THE SORT DOES NOT REACH THEM. Interleaving them by
         * size or by date would scatter the way into the tree through the files — and a folder has
         * no size to compare against a file's. Every file manager settles this the same way, and
         * it is what makes "the first page holds the folders" a rule somebody can rely on.
         */
        $foldersTotal = (clone $folders)->count();
        $mediaQuery = $this->browse->query($query);
        $mediaTotal = (clone $mediaQuery)->count();

        $offset = ($query->page - 1) * $query->perPage;

        /* ⚠️ NO UPPER BOUND ON WHAT IS TAKEN, BECAUSE THE SKIP ALREADY SETS IT. Past the last
         * folder there is nothing left to hand back, so `min(perPage, remaining)` was an
         * expression no mutation could tell from `perPage` — a line to maintain that said
         * nothing. What must be computed is where to start. */
        $folderSlice = $folders
            ->skip(min($offset, $foldersTotal))
            ->take($query->perPage)
            ->get();

        /* ⚠️ WHAT THE FOLDERS DID NOT FILL, AND NOTHING MORE. Past the last folder the media pick
         * up where the count left off, so no row is shown twice and none is skipped. */
        $media = $mediaQuery
            ->skip(max(0, $offset - $foldersTotal))
            ->take($query->perPage - $folderSlice->count())
            ->get();

        $total = $foldersTotal + $mediaTotal;

        return new JsonResponse([
            'data' => [
                'folder' => $folder === null ? null : new FolderResource($folder),
                'breadcrumbs' => $folder === null
                    ? []
                    : FolderResource::collection($this->tree->breadcrumbs($folder)),
                'folders' => FolderResource::collection($folderSlice),
                'media' => MediaResource::collection($media),
            ],
            'meta' => [
                'current_page' => $query->page,
                /* ⚠️ AT LEAST ONE PAGE, EVEN EMPTY. "Page 1 of 0" is a sentence nobody can act on,
                 * and a control built from it offers no page to go to. */
                'last_page' => max(1, (int) ceil($total / $query->perPage)),
                'per_page' => $query->perPage,
                'total' => $total,
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
