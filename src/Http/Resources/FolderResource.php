<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * THE PUBLIC SHAPE OF A FOLDER.
 *
 * ⚠️ `path` IS RETURNED, BUT AS A LABEL. It is a DISPLAY path, derived from the branch's slugs;
 * it names nothing on the storage and must never be used to build a file URL. Where the bytes
 * are filed is decided elsewhere, on write.
 *
 * @mixin MediaFolder
 */
final class FolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * ⚠️ A STRING, WHATEVER THE DRIVER KEYS ON — see `MediaResource` for the whole of
             * it. `standalone` keys on a `uuid`, the `legacy` preset on the host's integer `id`,
             * and the published contract says `string` for both.
             */
            'id' => (string) $this->getRouteKey(),
            'name' => $this->name,
            'slug' => $this->slug,
            'path' => $this->path,
            'depth' => (int) $this->depth,
            'parent_id' => MediaResource::key($this->resource->parent?->getRouteKey()),
            'trashed_at' => optional($this->deleted_at)->toAtomString(),
            'created_at' => optional($this->created_at)->toAtomString(),
            'updated_at' => optional($this->updated_at)->toAtomString(),
        ];
    }
}
