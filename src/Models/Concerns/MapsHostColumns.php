<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Models\Concerns;

use Kryption\MediaHub\Models\MediaFolder;
use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Backends\ColumnMap;

/**
 * THE MODEL SPEAKS THE HOST'S SCHEMA, AND THE REST OF THE PACKAGE IGNORES IT.
 *
 * ⚠️ THIS IS THE PACKAGE'S ARBITER. If plugging the library onto existing tables requires
 * touching the rest of the code, the abstraction is wrong — and that has to be known before a
 * single view is written. Everything of that kind is therefore concentrated here: elsewhere we
 * write `path`, `scope_key` or `folder_id` without knowing what they are called in the database.
 *
 * ⚠️ THREE TRANSLATIONS, AND THEY ARE NOT OF THE SAME NATURE:
 *
 *   1. the NAME — `path` becomes `url`. A plain substitution, but it must hold for attributes
 *      AND for queries, otherwise the model reads one column while the filter interrogates
 *      another;
 *   2. the VALUE — "no folder" is `null` here and `0` there, "private" is a string here and a
 *      `0` there. A name translation alone would let through values with the right shape and
 *      the wrong meaning;
 *   3. the ABSENCE — the file name, the extension and the family do not exist in the target
 *      schema. They are DERIVED on read, and refused for filtering: an accessor is not an
 *      index, and pretending otherwise would produce silently wrong listings.
 *
 * ⚠️ AND THE TRANSLATION ONLY APPLIES TO KNOWN KEYS. Relations, attributes added by the host
 * and everything else pass through to `parent::` untouched: an interceptor that captured
 * everything would break `folder`, `conversions` and the first accessor to come along.
 */
trait MapsHostColumns
{
    private static ?ColumnMap $map = null;

    abstract public static function columnMap(): ColumnMap;

    /** The PHYSICAL name of a logical column — to write a query. Raises if it is absent. */
    public static function column(string $logical): string
    {
        return static::columnMap()->require($logical);
    }

    public static function hasColumn(string $logical): bool
    {
        return static::columnMap()->has($logical);
    }

    public function getRouteKeyName(): string
    {
        return static::columnMap()->routeKey();
    }

    /**
     * BOUND A QUERY TO A FOLDER — or to the root.
     *
     * ⚠️ THIS IS A METHOD RATHER THAN A `whereNull` WRITTEN ON THE SPOT, because "the root"
     * changes shape from one schema to the next. On the real target, `folder_id` is `NOT NULL
     * DEFAULT 0`: a `whereNull` returns zero rows there, that is, an empty library and no error.
     */
    public function scopeAtParent(Builder $query, string $logicalColumn, mixed $parentKey): Builder
    {
        $column = static::column($logicalColumn);
        $root = static::columnMap()->rootValue();

        if ($parentKey !== null) {
            return $query->where($column, $parentKey);
        }

        $table = $this->getTable();
        $folders = new MediaFolder();

        return $query->where(function (Builder $atRoot) use ($column, $root, $table, $folders): void {
            if ($root === null) {
                $atRoot->whereNull($column);
            } else {
                $atRoot->where($column, $root);
            }

            /*
             * ⚠️ AND WHATEVER NAMES A FOLDER THAT IS NOT THERE, because otherwise it is nowhere.
             * A row pointing at a folder whose record has gone is neither at the root nor inside
             * anything that can be opened: it is alive, it occupies storage, and no screen can
             * reach it — including the one that would let somebody move or delete it. Measured on
             * a production library where 40 of an organisation's 65 files named a folder that
             * did not exist, leaving 25 visible.
             *
             * ⚠️ A FOLDER CAN VANISH WITHOUT ANYBODY DOING ANYTHING WRONG — a data migration, a
             * deletion made in SQL, an import that brought files without their tree. The root is
             * the only place where such a file becomes reachable again, and reaching it is the
             * precondition for every other repair.
             *
             * ⚠️ SOFT-DELETED IS NOT ABSENT, AND THE DIFFERENCE IS DELIBERATE. A folder in the
             * trash still exists and is a state somebody chose; pulling its contents up to the
             * root would undo that choice. Only a missing RECORD counts here.
             */
            $atRoot->orWhere(function (Builder $orphan) use ($column, $table, $folders): void {
                /*
                 * ⚠️ THE SUBQUERY IS ALIASED, AND WITHOUT THAT IT ANSWERS ABOUT ITSELF. A folder's
                 * parent is another folder, so the inner `from` names the SAME table as the outer
                 * query: unaliased, `folders.id = folders.parent_id` compares each inner row with
                 * itself and is false for every folder that is not its own parent — which is all
                 * of them. Every folder then looked orphaned, and the whole tree flattened onto
                 * the root. Two tests about trashed folders said so immediately.
                 */
                $alias = 'mediahub_parent_lookup';

                $orphan->whereNotNull($column)
                    ->whereNotExists(function ($exists) use ($column, $table, $folders, $alias): void {
                        $exists->select($folders->getKeyName())
                            ->from($folders->getTable().' as '.$alias)
                            ->whereColumn(
                                $alias.'.'.$folders->getKeyName(),
                                $table.'.'.$column
                            );
                    });
            });
        });
    }

    /**
     * ⚠️ WE ONLY TRANSLATE WHAT THE MAP KNOWS. A name absent from the map is returned as is:
     * that is what lets relations and host-specific columns pass through unharmed.
     */
    public function getAttribute($key)
    {
        $map = static::columnMap();

        if (! $this->isMappedColumn($key)) {
            return parent::getAttribute($key);
        }

        $physical = $map->physical($key);

        if ($physical === null) {
            return $this->derive($key);
        }

        return $this->translateOut($key, parent::getAttribute($physical));
    }

    public function setAttribute($key, $value)
    {
        $map = static::columnMap();

        if (! $this->isMappedColumn($key)) {
            return parent::setAttribute($key, $value);
        }

        $physical = $map->physical($key);

        /*
         * ⚠️ WRITING TO AN ABSENT COLUMN IS IGNORED, SILENTLY AND DELIBERATELY. The package
         * sets `checksum` and `width` on every upload; raising would make a perfectly valid
         * upload fail because the host's schema is poorer. What cannot be kept is not kept —
         * and `hasColumn()` lets whoever depends on it find out BEFORE counting on it.
         */
        if ($physical === null) {
            return $this;
        }

        return parent::setAttribute($physical, $this->translateIn($key, $value));
    }

    private function isMappedColumn(string $key): bool
    {
        return in_array($key, static::logicalColumns(), true);
    }

    /**
     * ⚠️ THE LIST IS EXPLICIT, AND THAT IS DELIBERATE. Relying on "everything in the map" would
     * leave the columns that are not renamed outside the translation filter — therefore without
     * VALUE conversion, when `visibility` and `folder_id` need it even where their name does
     * not change.
     *
     * @return array<int, string>
     */
    abstract protected static function logicalColumns(): array;

    /** What is computed when the column does not exist. */
    abstract protected function derive(string $logical): mixed;

    /** The value as the package expects it, from the one the database keeps. */
    abstract protected function translateOut(string $logical, mixed $value): mixed;

    /** The value as the database expects it, from the one the package supplies. */
    abstract protected function translateIn(string $logical, mixed $value): mixed;
}
