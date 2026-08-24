<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * WHO IS ALLOWED TO DO WHAT — the fine mesh, on top of the scoping.
 *
 * ⚠️ THIS IS NOT THE SCOPING, AND CONFUSING THE TWO WOULD BE SERIOUS. The scope decides what
 * EXISTS for the caller: it is a global scope, on the model, and nothing goes around it. This
 * policy decides, within what exists, what may be done with it — a reader who sees everything
 * and deletes nothing, a contributor who uploads without being able to empty the trash.
 *
 * ⚠️ ITS DEFAULT ALLOWS EVERYTHING, AND THAT IS DEFENSIBLE BECAUSE IT IS NOT THE ONLY BARRIER.
 * The package's routes live inside the host's middleware group — `web` and `auth` by default —
 * and the scope already bounds what is reachable. A default that refused everything would make
 * the package unusable without configuration; a default that allows everything leaves exactly
 * the two barriers the host has already put in place.
 *
 * ⚠️ AND IT RECEIVES THE MODEL, NOT AN IDENTIFIER. The object is therefore already resolved,
 * therefore already through the scope: the policy never has to ask whether the thing exists.
 */
interface AccessPolicy
{
    /** May the library be browsed? */
    public function browse(): bool;

    /** May files be uploaded to it? */
    public function upload(): bool;

    /**
     * May THE BYTES be retrieved — displayed, downloaded, archived?
     *
     * ⚠️ THIS IS NOT THE SAME QUESTION AS `browse()`, AND CONFUSING THEM IS EXPENSIVE. Seeing a
     * file's record, its thumbnail and its dimensions is not obtaining the original: plenty of
     * products let a library be consulted without allowing files out of it. Without this
     * permission, a policy could forbid MODIFYING a media and let anyone download it — the
     * opposite of what is being protected.
     */
    public function download(Media $media): bool;

    /** May this item be renamed, moved, annotated, copied? */
    public function modify(Media|MediaFolder $item): bool;

    /** May it be trashed, restored, deleted for good? */
    public function destroy(Media|MediaFolder $item): bool;
}
