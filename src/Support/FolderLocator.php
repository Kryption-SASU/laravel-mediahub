<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Exceptions\ItemNotFound;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * FINDING A FOLDER NAMED BY THE CLIENT — the only place where that happens.
 *
 * ⚠️ "ABSENT" AND "NOT FOUND" ARE NOT THE SAME ANSWER, and confusing them is a classic.
 * Sending nothing means "the root"; sending a key that does not resolve means a folder was
 * asked for that does not exist — or is not ours. Treating the second as the first would land a
 * file at the root when somebody thought they were uploading it elsewhere, and would move to
 * the root what they thought they were filing.
 *
 * ⚠️ AND RESOLUTION GOES THROUGH THE MODEL, THEREFORE THROUGH THE SCOPE. A foreign folder does
 * not resolve: this is where moving a file into another customer's folder is closed off.
 */
final class FolderLocator
{
    public function optional(mixed $key): ?MediaFolder
    {
        if ($key === null || $key === '') {
            return null;
        }

        $key = (string) $key;

        $folder = MediaFolder::query()
            ->with('parent')
            ->where((new MediaFolder())->getRouteKeyName(), $key)
            ->first();

        if ($folder === null) {
            throw ItemNotFound::folder($key);
        }

        return $folder;
    }
}
