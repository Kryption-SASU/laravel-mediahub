<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Contracts;

/**
 * WHO IS DOING THIS — the third question a host answers, beside "what may they see" and "what
 * may they do".
 *
 * ⚠️ THE PACKAGE CANNOT GUESS, AND IT USED NOT TO ASK. `UploadController` and `FolderController`
 * called their actions with no owner at all, so a file uploaded through the API and a folder
 * created through it belonged to nobody. On the package's own tables that is merely a missing
 * fact; on an adopted schema whose `user_id` is `NOT NULL` — the `legacy` preset's, read from a
 * real database — the insert is refused outright, and both features fail with a constraint
 * violation rather than a message. Measured on 25/08/2026.
 *
 * ⚠️ AND IT IS A CONTRACT RATHER THAN A CALL TO `auth()`. A host may key ownership on something
 * other than the authenticated user — a team, a tenant, an impersonated account — and a package
 * that reached for the session directly would leave them no way to say so, exactly as reaching
 * for the current tenant would have done for the scope.
 */
interface MediaOwner
{
    /**
     * The key of whoever is acting, or `null` when nobody is.
     *
     * ⚠️ `null` IS A LEGITIMATE ANSWER, not a failure: a queue worker, a console command or an
     * open library all act with no user. The caller writes nothing rather than inventing one.
     */
    public function currentKey(): int|string|null;

    /**
     * The morph type of that owner, or `null` where ownership is not polymorphic.
     *
     * ⚠️ IT IS ONLY EVER WRITTEN WHERE THE SCHEMA HAS SOMEWHERE TO PUT IT. The adopted tables
     * carry a plain `user_id` and no type at all; writing one would name a column that does not
     * exist, and the first upload would fail on SQL rather than on a rule.
     */
    public function currentType(): ?string;
}
