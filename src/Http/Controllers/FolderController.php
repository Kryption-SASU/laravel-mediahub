<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Actions\MoveFolder;
use Kryption\MediaHub\Actions\RenameFolder;
use Kryption\MediaHub\Http\Requests\FolderRequest;
use Kryption\MediaHub\Http\Resources\FolderResource;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderLocator;

/**
 * FOLDERS.
 *
 * ⚠️ A `PATCH` THAT UPDATES THE SUPPLIED FIELDS IS NOT A SWITCH. The "one route per operation"
 * rule targets the single entry point that decides its behaviour from an `action` field in the
 * request body — twelve branches, one authorisation. Here every field has a fixed meaning, and
 * the authorisation is the same for all of them: modify this folder.
 *
 * ⚠️ AND "MOVE TO THE ROOT" IS TOLD APART FROM "DO NOT MOVE" BY THE PRESENCE OF THE KEY, not by
 * its value. Without that distinction, a simple rename would send the folder back to the root —
 * a move nobody asked for, and one that carries a whole branch with it.
 */
final class FolderController
{
    public function __construct(
        private readonly CreateFolder $create,
        private readonly RenameFolder $rename,
        private readonly MoveFolder $move,
        private readonly FolderLocator $folders,
    ) {
    }

    public function store(FolderRequest $request): JsonResponse
    {
        $parent = $this->folders->optional($request->input('parent'));

        $folder = ($this->create)((string) $request->input('name'), $parent);

        return (new FolderResource($folder->load('parent')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(FolderRequest $request, MediaFolder $folder): FolderResource
    {
        if ($request->filled('name')) {
            ($this->rename)($folder, (string) $request->input('name'));
        }

        if ($request->exists('parent')) {
            ($this->move)($folder, $this->folders->optional($request->input('parent')));
        }

        return new FolderResource($folder->fresh()->load('parent'));
    }
}
