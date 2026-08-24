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
use Kryption\MediaHub\Models\Concerns\MapsHostColumns;
use Kryption\MediaHub\Models\Concerns\ScopedToMediaScope;

/**
 * A LIBRARY FOLDER.
 *
 * ⚠️ ITS DELETION IS SOFT, AND IT CARRIES ITS CONTENT WITH IT. A folder emptied of its rows
 * while its files stay on the storage produces invisible orphans — 6,302 of them were counted
 * on a real case.
 */
class MediaFolder extends Model
{
    use MapsHostColumns;
    use ScopedToMediaScope;
    use SoftDeletes;

    /** How many ancestors we climb at most to reconstruct a missing path. */
    private const MAX_CLIMB = 64;

    private const LOGICAL = [
        'uuid', 'parent_id', 'name', 'slug', 'path', 'depth', 'scope_key', 'visibility', 'meta',
        'owner_type', 'owner_id',
    ];

    /** @var array<string, string> */
    private const CASTS = [
        'meta' => 'array',
        'depth' => 'integer',
    ];

    protected $guarded = [];

    /**
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
        return HostSchema::forFolders();
    }

    /** @return array<int, string> */
    protected static function logicalColumns(): array
    {
        return self::LOGICAL;
    }

    public function getTable(): string
    {
        return HostSchema::table('folders');
    }

    protected static function booted(): void
    {
        static::creating(static function (self $folder): void {
            if (static::hasColumn('uuid')) {
                $folder->uuid ??= (string) Str::uuid();
            }

            $folder->slug ??= Str::slug((string) $folder->name);
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, static::column('parent_id'));
    }

    /**
     * ⚠️ THE PATH AND THE DEPTH ARE RECONSTRUCTED BY CLIMBING, for want of being stored. That
     * costs one query per level — acceptable because folders are counted in tens, never in
     * millions, and because the climb is bounded.
     *
     * ⚠️ AND THE TRADE-OFF IS A PLEASANT ONE: without a materialised path, renaming or moving a
     * folder has NO descendants to rewrite. What is not derived cannot lie.
     */
    protected function derive(string $logical): mixed
    {
        return match ($logical) {
            'uuid' => $this->getKey(),
            'path' => implode('/', $this->slugsFromRoot()),
            'depth' => max(0, count($this->slugsFromRoot()) - 1),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function slugsFromRoot(): array
    {
        $slugs = [(string) $this->getAttribute('slug')];
        $current = $this;
        $guard = 0;

        while ($guard++ < self::MAX_CLIMB) {
            $parentId = $current->getAttribute('parent_id');

            if ($parentId === null) {
                break;
            }

            $parent = static::withTrashed()->find($parentId);

            if ($parent === null) {
                break;
            }

            array_unshift($slugs, (string) $parent->getAttribute('slug'));
            $current = $parent;
        }

        return $slugs;
    }

    protected function translateOut(string $logical, mixed $value): mixed
    {
        $map = static::columnMap();

        return match ($logical) {
            'parent_id' => $value === null || $value === $map->rootValue() ? null : $value,
            'visibility' => HostSchema::visibilityOut($value),
            default => $value,
        };
    }

    protected function translateIn(string $logical, mixed $value): mixed
    {
        return match ($logical) {
            'parent_id' => $value === null ? static::columnMap()->rootValue() : $value,
            'visibility' => HostSchema::visibilityIn($value),
            default => $value,
        };
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, static::column('parent_id'));
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, Media::column('folder_id'));
    }
}
