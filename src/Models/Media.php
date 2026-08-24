<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Kryption\MediaHub\Backends\ColumnMap;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Contracts\MediaTypeResolver;
use Kryption\MediaHub\Enums\MediaType;
use Kryption\MediaHub\Models\Concerns\MapsHostColumns;
use Kryption\MediaHub\Models\Concerns\ScopedToMediaScope;

/**
 * A MEDIA.
 *
 * ⚠️ `path` IS A PATH, NEVER A URL. The database keeps a disk and a relative path; the URL is
 * computed at serving time. That is what makes it possible to change storage or turn signing on
 * without migrating a single row — and it is what the original module lacked, where one read
 * looked for a LOCAL file starting from a REMOTE URL, so that downloading had been broken since
 * the move to the cloud without anyone seeing it.
 *
 * ⚠️ AND THE BYTES OUTLIVE THE TRASH. A soft delete erases nothing on the storage: any cleanup
 * operation that forgets `withTrashed()` destroys what was restorable.
 */
class Media extends Model
{
    use MapsHostColumns;
    use ScopedToMediaScope;
    use SoftDeletes;

    /** The columns the package names itself — therefore the ones that need translating. */
    private const LOGICAL = [
        'uuid', 'folder_id', 'disk', 'path', 'name', 'file_name', 'extension',
        'mime_type', 'type', 'size', 'width', 'height', 'duration', 'checksum',
        'custom_properties', 'meta', 'scope_key', 'visibility', 'owner_type', 'owner_id',
    ];

    /** @var array<string, string> */
    private const CASTS = [
        'custom_properties' => 'array',
        'meta' => 'array',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
    ];

    protected $guarded = [];

    /**
     * ⚠️ THE CASTS ARE DECLARED ON THE PHYSICAL NAMES, AND THEY HAVE TO BE. Eloquent applies a
     * cast to the value as it comes out of the row: declaring it on the logical name would make
     * it inert as soon as a column is renamed, and a JSON block would come back as a string —
     * without an error, but impossible to read.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $map = static::columnMap();
        $casts = [];

        foreach (self::CASTS as $logical => $type) {
            $physical = $map->physical($logical);

            if ($physical !== null) {
                $casts[$physical] = $type;
            }
        }

        return $casts;
    }

    public static function columnMap(): ColumnMap
    {
        return HostSchema::forMedia();
    }

    /** @return array<int, string> */
    protected static function logicalColumns(): array
    {
        return self::LOGICAL;
    }

    public function getTable(): string
    {
        return HostSchema::table('files');
    }

    protected static function booted(): void
    {
        static::creating(static function (self $media): void {
            /*
             * ⚠️ WITHOUT A `uuid` COLUMN THIS WRITE IS IGNORED — and that is the intended
             * behaviour. The target schema has none; its route key is the identifier, and the
             * defence against enumeration then rests entirely on the scoping.
             */
            if (static::hasColumn('uuid')) {
                $media->uuid ??= (string) Str::uuid();
            }
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, static::column('folder_id'));
    }

    /**
     * ⚠️ WHAT IS COMPUTED FOR WANT OF BEING STORED. These are READ values: they are displayed,
     * they are neither sorted nor filtered on — an accessor is not an index.
     */
    protected function derive(string $logical): mixed
    {
        $path = (string) $this->getAttribute('path');

        return match ($logical) {
            /* The route key must exist even without a dedicated column, otherwise no URL. */
            'uuid' => $this->getKey(),
            'file_name' => $path === '' ? null : basename($path),
            'extension' => $path === '' ? null : (strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: null),
            'type' => app(MediaTypeResolver::class)
                ->resolve((string) $this->getAttribute('mime_type'), (string) pathinfo($path, PATHINFO_EXTENSION))
                ->value,
            default => null,
        };
    }

    protected function translateOut(string $logical, mixed $value): mixed
    {
        $map = static::columnMap();

        return match ($logical) {
            /* `0` means "at the root" in the target schema; the package says `null`. */
            'folder_id' => $value === null || $value === $map->rootValue() ? null : $value,
            'visibility' => HostSchema::visibilityOut($value),
            default => $value,
        };
    }

    protected function translateIn(string $logical, mixed $value): mixed
    {
        return match ($logical) {
            'folder_id' => $value === null ? static::columnMap()->rootValue() : $value,
            'visibility' => HostSchema::visibilityIn($value),
            default => $value,
        };
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(MediaConversion::class, 'media_id');
    }

    /**
     * THE RELATIONS WE EAGER-LOAD — and the ones that do not exist here.
     *
     * ⚠️ AN ADOPTED SCHEMA MAY HAVE NO CONVERSIONS TABLE. Loading the relation anyway does not
     * return an empty list: it queries a table that is not there, so it RAISES, and the whole
     * screen falls over a thumbnail. The question is therefore asked here, once, rather than in
     * every caller.
     *
     * @return array<int, string>
     */
    public static function eagerLoadable(): array
    {
        return HostSchema::hasTable('conversions') ? ['conversions', 'folder'] : ['folder'];
    }

    /** The nature of the media, as it was deduced when recorded. */
    public function mediaType(): MediaType
    {
        return MediaType::tryFrom((string) $this->type) ?? MediaType::Other;
    }

}
