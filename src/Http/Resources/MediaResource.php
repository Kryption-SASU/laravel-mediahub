<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Kryption\MediaHub\Contracts\UrlGenerator;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;

/**
 * THE PUBLIC SHAPE OF A MEDIA — and the picker's contract.
 *
 * ⚠️ WHAT IS NOT HERE MATTERS AS MUCH AS WHAT IS. No `disk`, no `path`, no `checksum`, no
 * `scope_key`. The storage path reveals the internal tree and the bucket name; the checksum
 * makes it possible to find out whether content you already hold exists elsewhere in the
 * product — a comparison no user is entitled to make. The original module serialised the whole
 * model.
 *
 * ⚠️ AND THE EXPOSED IDENTIFIER IS THE ROUTE KEY, NOT THE DATABASE ONE. It is also what lets
 * the client ask for a fresh URL when theirs has expired: the URL itself cannot carry it — a
 * storage signature covers the whole query string and refuses any added parameter.
 *
 * @mixin Media
 */
final class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $urls = app(UrlGenerator::class);

        return [
            'id' => $this->getRouteKey(),
            'name' => $this->name,
            'file_name' => $this->file_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'type' => $this->type,
            'size' => (int) $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            /*
             * ⚠️ ALWAYS PRESENT, EVEN AS `null`. A `whenLoaded()` would make the key DISAPPEAR
             * when the relation is not loaded: the client could no longer tell "this media is
             * at the root" from "the server did not say", and a picker contract whose keys come
             * and go is not a contract. The price is that callers MUST load the relation — they
             * all do.
             */
            'folder_id' => $this->resource->folder?->getRouteKey(),
            'custom_properties' => $this->custom_properties ?? [],
            'url' => $urls->url($this->resource),
            'download_url' => $urls->downloadUrl($this->resource),
            'thumbnail_url' => $this->thumbnail($urls),
            'trashed_at' => optional($this->deleted_at)->toAtomString(),
            'created_at' => optional($this->created_at)->toAtomString(),
            'updated_at' => optional($this->updated_at)->toAtomString(),
        ];
    }

    /**
     * ⚠️ `null` IS A NORMAL ANSWER, NOT AN ERROR. A derivative is built outside the request:
     * between the upload and its completion, there is none. And a video, a document or an
     * archive never produce one. It is up to the screen to show a placeholder — serving the
     * original instead would download twenty megabytes for a thumbnail.
     */
    private function thumbnail(UrlGenerator $urls): ?string
    {
        if (! $this->resource->relationLoaded('conversions')) {
            return null;
        }

        $thumbnail = $this->resource->conversions
            ->first(static fn (MediaConversion $conversion): bool => $conversion->state === ConversionState::Ready);

        return $thumbnail === null ? null : $urls->conversionUrl($thumbnail);
    }
}
