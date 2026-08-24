<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\Request;
use Kryption\MediaHub\Actions\CopyMedia;
use Kryption\MediaHub\Actions\MoveMedia;
use Kryption\MediaHub\Actions\RenameMedia;
use Kryption\MediaHub\Actions\UpdateMediaMeta;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Http\Requests\UpdateMediaRequest;
use Kryption\MediaHub\Http\Resources\MediaResource;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\FolderLocator;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A MEDIA'S RECORD — showing it, changing it, duplicating it.
 *
 * ⚠️ THE RELATIONS ARE LOADED BEFORE THE RESOURCE, not during. The resource returns the
 * folder's identifier and the thumbnail's URL: letting them load by themselves works, and makes
 * two extra queries per media — invisible on a single record, ruinous on a list, and it is the
 * SAME serialisation code serving both.
 *
 * ⚠️ AND "MOVE TO THE ROOT" IS TOLD APART FROM "DO NOT MOVE" BY THE PRESENCE OF THE KEY.
 * Without that, a simple rename would detach the file from its folder.
 */
final class MediaController
{
    public function __construct(
        private readonly RenameMedia $rename,
        private readonly UpdateMediaMeta $annotate,
        private readonly MoveMedia $move,
        private readonly FolderLocator $folders,
    ) {
    }

    public function show(Request $request, Media $media): MediaResource
    {
        return new MediaResource($media->load(Media::eagerLoadable()));
    }

    public function update(UpdateMediaRequest $request, Media $media): MediaResource
    {
        if ($request->filled('name')) {
            ($this->rename)($media, (string) $request->input('name'));
        }

        if ($request->exists('properties')) {
            ($this->annotate)($media, (array) $request->input('properties', []));
        }

        if ($request->exists('folder')) {
            ($this->move)($media, $this->folders->optional($request->input('folder')));
        }

        return new MediaResource($media->fresh()->load(['conversions', 'folder']));
    }

    /**
     * ⚠️ COPYING IS A MODIFICATION ON THE SOURCE SIDE AND AN UPLOAD ON THE TARGET SIDE, and
     * both permissions are required. Asking for only one would let either a reader create
     * files, or an uploader duplicate what they are not allowed to touch.
     */
    public function copy(Request $request, Media $media, CopyMedia $copy, AccessPolicy $policy): MediaResource
    {
        if (! $policy->modify($media) || ! $policy->upload()) {
            throw new AuthorizationException();
        }

        $target = $this->folders->optional($request->input('folder'));

        return new MediaResource($copy($media, $target)->load(Media::eagerLoadable()));
    }
}
