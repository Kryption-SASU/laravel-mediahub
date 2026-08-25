<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Kryption\MediaHub\Contracts\MediaOwner;

/**
 * THE ORDINARY ANSWER: whoever is signed in.
 *
 * ⚠️ IT IS THE DEFAULT BECAUSE IT IS RIGHT ALMOST EVERYWHERE, and replaceable because it is not
 * right everywhere. A host keying ownership on a team, a tenant or an impersonated account binds
 * `MediaOwner` to its own and nothing here notices.
 *
 * ⚠️ AND IT ANSWERS `null` RATHER THAN FAILING WHEN NOBODY IS SIGNED IN. A queue worker
 * generating derivatives, a console command importing a directory and a public library all run
 * with no user; raising there would turn a legitimate situation into a crash, and inventing an
 * identifier would attribute files to somebody who never touched them.
 */
final class AuthenticatedOwner implements MediaOwner
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function currentKey(): int|string|null
    {
        $key = $this->auth->guard()->id();

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * ⚠️ THE CLASS OF THE USER, WHICH IS WHAT A MORPH MAP EXPECTS. Laravel resolves an alias
     * from it when the host declared one, so returning the alias here would work in one
     * configuration and produce an unresolvable type in the other.
     */
    public function currentType(): ?string
    {
        $user = $this->auth->guard()->user();

        return $user === null ? null : $user::class;
    }
}
