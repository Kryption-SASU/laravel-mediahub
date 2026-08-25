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
            /*
             * ⚠️ A STRING, WHATEVER THE DRIVER KEYS ON. `standalone` keys on a `uuid` and the
             * `legacy` preset on the host's integer `id`; left uncast, the same field is a
             * string on one installation and a number on another, and the published contract —
             * which says `id: string` — is only true on one of them. The suite never caught it
             * because it only ever runs `standalone`, where the cast changes nothing.
             */
            'id' => (string) $this->getRouteKey(),
            'name' => $this->name,
            'file_name' => $this->file_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'type' => $this->type,
            'size' => (int) $this->size,
            /*
             * ⚠️ READ FROM WHICHEVER PLACE THIS SCHEMA HAS. Where the columns exist they are the
             * answer; where they do not — and the shipped `legacy` preset maps both to `null`,
             * because the tables really do not carry them — the upload parks what it measured in
             * the free-form properties instead. One field on the wire either way, so no client
             * has to know which kind of schema it is talking to.
             */
            'width' => $this->measured('width'),
            'height' => $this->measured('height'),
            'duration' => $this->duration,
            /*
             * ⚠️ ALWAYS PRESENT, EVEN AS `null`. A `whenLoaded()` would make the key DISAPPEAR
             * when the relation is not loaded: the client could no longer tell "this media is
             * at the root" from "the server did not say", and a picker contract whose keys come
             * and go is not a contract. The price is that callers MUST load the relation — they
             * all do.
             */
            'folder_id' => self::key($this->resource->folder?->getRouteKey()),
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
     * ⚠️ ONLY A WHOLE NUMBER COMES BACK. The properties are free-form and written by hosts as
     * well as by us: a string, a float or a leftover `null` in there would otherwise reach a
     * client that was promised `number | null`.
     */
    private function measured(string $field): ?int
    {
        $column = $this->resource->getAttribute($field);

        if (is_numeric($column)) {
            return (int) $column;
        }

        $kept = ($this->resource->custom_properties ?? [])[$field] ?? null;

        return is_numeric($kept) ? (int) $kept : null;
    }

    /**
     * ⚠️ A KEY IS A STRING, AND THE ABSENCE OF ONE STAYS `null`. Casting the absence would send
     * `""` where the client reads "this media is at the root", and an empty string is a key it
     * would then hand back on the next write.
     */
    public static function key(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
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
