<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Support\Carbon;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * SWEEPING THE TRASH — whatever has been sitting there long enough.
 *
 * ⚠️ THIS ONE, HOWEVER, IS A MAINTENANCE OPERATION, and that is where the trap is. A scheduled
 * task has NEITHER SESSION NOR USER: the current scope there is worth whatever the host decided,
 * often `null`. Depending on its implementation, the same command will sweep nothing at all, or
 * sweep everybody — and both will go unnoticed, one because it does nothing, the other because
 * it does exactly what was expected, on too large a scale.
 *
 * ⚠️ HENCE AN EXPLICIT ARGUMENT RATHER THAN A SILENT WORKAROUND. `$everyScope` says what it
 * does; a query written by hand to dodge the scope says nothing at all, and nobody re-reads it.
 */
final class PruneTrash
{
    public function __construct(private readonly ForceDeleteItems $purge)
    {
    }

    public function __invoke(int $days, bool $everyScope = false): ResolvedItems
    {
        $limit = Carbon::now()->subDays(max(0, $days));

        $media = ($everyScope ? Media::withoutMediaScope()->onlyTrashed() : Media::onlyTrashed())
            ->where('deleted_at', '<=', $limit)
            ->get();

        $folders = ($everyScope ? MediaFolder::withoutMediaScope()->onlyTrashed() : MediaFolder::onlyTrashed())
            ->where('deleted_at', '<=', $limit)
            ->get();

        return ($this->purge)(new ResolvedItems($media, $folders));
    }
}
