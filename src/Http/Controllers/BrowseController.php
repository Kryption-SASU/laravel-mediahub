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

        $subfolders = MediaFolder::query()
            ->with('parent')
            ->atParent('parent_id', $folder?->getKey())
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

        $folder = MediaFolder::query()
            ->with('parent')
            ->where((new MediaFolder())->getRouteKeyName(), (string) $key)
            ->first();

        if ($folder === null) {
            throw ItemNotFound::folder((string) $key);
        }

        return $folder;
    }
}
