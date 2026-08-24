<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Backends;

use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Enums\MediaType;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\MimeMediaTypeResolver;

/**
 * FILTERING BY FAMILY — on the column when it exists, on the MIME type otherwise.
 *
 * ⚠️ WHEN THE FAMILY IS STORED, THE FILTER IS EXACT: it queries the very column the resolver
 * wrote. When it is not, the filter RECONSTRUCTS it in SQL — and that reconstruction can only
 * follow the rules of the DEFAULT resolver. A host replacing `MediaTypeResolver` would then see
 * its screens classify differently from its filters. That is the price of a schema without a
 * family column, and it is better written down than discovered.
 *
 * ⚠️ AND ONE RULE CANNOT BE RECONSTRUCTED AT ALL: settling ambiguous containers. A `.wma` comes
 * back as `video/x-ms-asf`, and only its EXTENSION says it is sound. The resolver knows that; a
 * `WHERE` clause on the MIME type alone does not. An "audio" filter on a schema without a
 * family column will therefore miss the WMA files, and a "video" filter will count them.
 *
 * ⚠️ FINALLY, `other` IS A NEGATION, NOT A LIST. Returning it as an empty list would give zero
 * results where the screen shows some — and nobody would know why.
 */
final class TypeFilter
{
    /** @var array<string, string> family => MIME prefix */
    private const PREFIXES = [
        'image' => 'image/',
        'video' => 'video/',
        'audio' => 'audio/',
    ];

    /**
     * @param  array<int, string>  $families
     */
    public function apply(Builder $query, array $families): Builder
    {
        if ($families === []) {
            return $query;
        }

        if (Media::hasColumn('type')) {
            return $query->whereIn(Media::column('type'), $families);
        }

        $mime = Media::column('mime_type');

        return $query->where(function (Builder $group) use ($families, $mime): void {
            foreach ($families as $family) {
                $this->family($group, $mime, $family);
            }
        });
    }

    private function family(Builder $group, string $mime, string $family): void
    {
        if (isset(self::PREFIXES[$family])) {
            $group->orWhere($mime, 'like', self::PREFIXES[$family].'%');

            return;
        }

        if ($family === MediaType::Document->value) {
            $group->orWhere(function (Builder $documents) use ($mime): void {
                $documents->whereIn($mime, array_keys(MimeMediaTypeResolver::DOCUMENTS))
                    ->orWhere($mime, 'like', MimeMediaTypeResolver::OFFICE_PREFIX.'%');
            });

            return;
        }

        if ($family === MediaType::Other->value) {
            $group->orWhere(function (Builder $rest) use ($mime): void {
                foreach (self::PREFIXES as $prefix) {
                    $rest->where($mime, 'not like', $prefix.'%');
                }

                $rest->whereNotIn($mime, array_keys(MimeMediaTypeResolver::DOCUMENTS))
                    ->where($mime, 'not like', MimeMediaTypeResolver::OFFICE_PREFIX.'%');
            });
        }

        /*
         * ⚠️ AN UNKNOWN FAMILY ADDS NOTHING TO THE GROUP, AND DOES NOT EMPTY IT EITHER. It is
         * simply ignored: `external` has no MIME type to query, and turning an unknown family
         * into "no results" would return an empty list for a typo in a parameter.
         */
    }
}
