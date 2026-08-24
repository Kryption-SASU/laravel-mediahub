<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;

/**
 * THE DEFAULT POLICY: what the scope lets you see, you may touch.
 *
 * ⚠️ IT SAYS ITS OWN NAME, AND THAT IS THE WHOLE POINT. A class called `AllowAll` invites the
 * belief that there is no barrier at all; this one names the barrier there is — the scope, plus
 * the host's middleware group. A product that wants roles writes its own implementation, and
 * that is the door provided for it.
 *
 * ⚠️ WHAT IT DOES NOT ALLOW FOR ALL THAT: seeing what belongs to another scope. That refusal
 * does not go through here and cannot be loosened from here.
 */
final class ScopeIsTheBoundary implements AccessPolicy
{
    public function browse(): bool
    {
        return true;
    }

    public function upload(): bool
    {
        return true;
    }

    public function download(Media $media): bool
    {
        return true;
    }

    public function modify(Media|MediaFolder $item): bool
    {
        return true;
    }

    public function destroy(Media|MediaFolder $item): bool
    {
        return true;
    }
}
