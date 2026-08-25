<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Backends\TypeFilter;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\ValueObjects\BrowseQuery;

/**
 * BROWSING THE LIBRARY.
 *
 * ⚠️ THE DERIVATIVES ARE EAGER-LOADED. A media resource returns its thumbnail's URL: without
 * eager loading, displaying a page of twenty-four files makes twenty-five queries, and the
 * defect only shows in production, on a full folder.
 *
 * ⚠️ AND THE SCOPING IS NOT WRITTEN HERE. It is a global scope, on the model: the day somebody
 * adds a screen, a command or an export, they will have nothing to think about. This listing is
 * precisely the one the original module filtered correctly — and its actions, not.
 */
final class BrowseMedia
{
    public function __invoke(BrowseQuery $query): LengthAwarePaginator
    {
        return $this->query($query)->paginate(perPage: $query->perPage, page: $query->page);
    }

    /**
     * THE SAME LISTING, WITHOUT DECIDING WHERE IT IS CUT.
     *
     * ⚠️ A LEVEL IS FOLDERS AND FILES, AND A PAGE OF ONE IS A PAGE OF BOTH. Paginating the media
     * alone put every folder on top of every page: a level with twelve folders showed sixty tiles
     * where it promised forty-eight, and "page 2 of 3" counted only half of what was on screen.
     * Whoever assembles the two has to be the one that cuts them, so this hands back the query
     * rather than a slice of it.
     */
    public function query(BrowseQuery $query): Builder
    {
        $builder = Media::query()->with(Media::eagerLoadable());

        if ($query->trashed) {
            $builder->onlyTrashed();
        }

        if ($query->folder !== null) {
            $builder->atParent('folder_id', $query->folder->getKey());
        } elseif ($query->rootOnly) {
            /*
             * ⚠️ "AT THE ROOT" AND "ANYWHERE" ARE NOT THE SAME REQUEST, and without this flag
             * they merge: opening the library would show the entire content of every folder,
             * flattened, which looks like a leak before it looks like a bug.
             */
            $builder->atParent('folder_id', null);
        }

        if ($query->search !== null) {
            $builder->where(Media::column('name'), 'like', '%'.$query->search.'%');
        }

        if ($query->types !== []) {
            (new TypeFilter())->apply($builder, $query->types);
        }

        return $builder
            ->orderBy(Media::column($query->column()), $query->descending ? 'desc' : 'asc')
            /*
             * ⚠️ A SECOND CRITERION, ALWAYS. A sort on a column with repeated values — a size,
             * a date to the second — leaves the order of ties to the engine: the same item then
             * appears on two pages, and another on none.
             */
            ->orderBy((new Media())->getKeyName(), 'desc');
    }
}
