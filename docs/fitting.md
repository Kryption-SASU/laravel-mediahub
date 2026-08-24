# Fitting it to your application

This package is meant to be configured, not forked. Every point where it touches your
application is a **contract with a working default**: you bind the ones your reality differs on,
and leave the rest alone.

The code you write to do that — the **glue** — lives in your application, never here. This page
is about how to write it, because it is the difference between a package that adapts and one
that gets copied.

---

## Why the glue cannot live in the package

The package knows nothing about your users, your tenants, your session, or the table you keep
quotas in. It cannot: the moment it did, it would depend on your application, and it would stop
being installable in anybody else's.

So it declares what it *needs to know* and asks. A contract is a question with a default answer:

> *"Which files belong to whoever is asking?"* — by default, all of them.

That default is right for a single-site application and wrong for a multi-tenant one. Answering
it is your business, and it is usually fifteen lines.

⚠️ **The defaults are not neutral, and one of them is dangerous.** The `MediaScope` shipped by
default constrains nothing — every tenant sees every file, and nothing on screen says so. If
your application has tenants, this is the first thing to bind, before you look at whether a
screen renders.

⚠️ **And the danger is in `constrain()`, not in the key.** The package calls `constrain()`
unconditionally, so a null key does not switch scoping off by itself — it is a scope that
returns the query untouched that does. Writing "if the key is null, do not filter" is the
natural thing to write, and it is the mistake: absence has to be filtered like anything else.

---

## Is any of it mandatory?

**To make the package work: no. None of it.**

```bash
composer require kryption/laravel-mediahub
```

That is a working media library — uploads, folders, trash, quotas, derivatives, signed URLs, the
screens. Every contract has a default that runs, and a single-site application can go to
production without writing one line of glue. That is the promise, and it is why the defaults are
chosen to be *correct* rather than merely present.

⚠️ **To make it correct in YOUR application: one of them usually is, and the package cannot
decide which.**

`MediaScope` defaults to *no scoping* — everyone sees everything. For a blog, a portfolio, a
company site, that is exactly right: there is one library and everybody who can reach the screen
may see it. For an application where records belong to tenants, it is a leak, and nothing on
screen says so. The package has no way to tell the two apart: it cannot know whether the
`organizations` table in your schema partitions your users or merely describes them.

So the honest rule is:

> Nothing is required for the package to **run**. One thing is required for it to be **right**,
> and only you know whether you are in that case.

### The three that depend on your situation

| Bind it when | Contract | Leaving the default means |
|---|---|---|
| your records belong to tenants | `MediaScope` | ⚠️ every tenant sees every file |
| you want storage limits | `QuotaPolicy` | unlimited — a choice, not a hazard |
| some people may see but not delete | `AccessPolicy` | whoever reaches the screen may do everything the scope allows |

The other nine have defaults that are *decisions*, not gaps: where files land, how they are
named, which disk, how a type is detected, what happens to a duplicate, who builds thumbnails,
how a URL is signed. Replace them when your trade says otherwise — not because they are missing.

### What "no glue at all" looks like

A single-site application, in full:

```php
// config/mediahub.php
'storage' => ['disk' => 's3'],
'routes'  => ['prefix' => 'media', 'middleware' => ['web', 'auth']],
```

```blade
<div data-mediahub></div>
```

⚠️ That last line assumes the ready-made bundle, which ships on tagged versions and has to be
published once — see [delivery](delivery.md). An application that builds its own JavaScript
imports the components instead, and needs no bundle at all.

There is no service provider to write, no class to implement, nothing to bind. Files land under
the folder given at upload, are named uniquely, get a thumbnail if an image library is present,
and are served through signed URLs. ⚠️ **The absence of glue here is not a shortcut — it is the
package behaving correctly for that shape of application.**

---

## A worked example: one library per tenant

Take an application where every record belongs to an organisation, and the current one is
resolved from the request or the session.

### 1. The contract

```php
namespace Kryption\MediaHub\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface MediaScope
{
    /** A stable key for whoever is asking, or `null` for no scoping at all. */
    public function currentKey(): ?string;

    /** Bounds a query to what that key may see. */
    public function constrain(Builder $query): Builder;
}
```

### 2. The glue

One file, in your application:

```php
<?php

declare(strict_types=1);

namespace App\Support\MediaHub;

use App\User;
use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Contracts\MediaScope;

/**
 * What separates one organisation's library from another's.
 *
 * ⚠️ Without this, the package does not partition anything. Its default is "no scoping", which
 * is correct for a single site and wrong here: every organisation would see every file, and
 * nothing on screen would say so.
 */
final class OrganizationMediaScope implements MediaScope
{
    public function currentKey(): ?string
    {
        $organization = User::currentOrganizationId();

        /*
         * ⚠️ ABSENCE IS A SCOPE OF ITS OWN, NEVER A WILDCARD. What decides that is `constrain()`
         * below, not this method: the package calls it unconditionally, so a scope that stops
         * filtering when the key is null opens the whole library — which is exactly what the
         * default implementation does, and why binding one is the first thing to do.
         */
        return $organization === null ? 'platform' : 'orgs/'.$organization;
    }

    public function constrain(Builder $query): Builder
    {
        return $query->where('scope_key', $this->currentKey());
    }
}
```

### 3. The binding

In a service provider:

```php
$this->app->singleton(MediaScope::class, OrganizationMediaScope::class);
```

That is the whole multi-tenant adaptation. The package writes `scope_key` on every record it
creates, and bounds every read and every write by `constrain()` — including the screen somebody
writes a year from now, because it is a global scope rather than a `where` clause anyone has to
remember.

### 4. The test

⚠️ **The glue is code, and it is the code that decides who sees what.** It deserves a test more
than most of what surrounds it — and it is easy to write, because it does one thing:

```php
public function test_two_organisations_do_not_see_each_other(): void
{
    $this->actingAsOrganisation(1);
    $mine = $this->uploadSomething();

    $this->actingAsOrganisation(2);

    self::assertNull(Media::find($mine->getKey()));
}

/**
 * ⚠️ And the dangerous case, which is decided in `constrain()`: no organisation at all must
 * be filtered like any other scope, never left unfiltered.
 */
public function test_no_organisation_is_a_scope_rather_than_a_wildcard(): void
{
    $this->actingAsOrganisation(1);
    $mine = $this->uploadSomething();

    $this->withoutOrganisation();

    self::assertNull(Media::find($mine->getKey()));
}
```

---

## The other contracts

The same shape applies to all of them: implement, bind, test. **Your binding always wins**,
whatever order service providers boot in.

| Contract | The question it asks | The default answer |
|---|---|---|
| `MediaScope` | who may see what | everyone sees everything |
| `PathGenerator` | where a file lands on the storage | the folder you give, sanitised |
| `FileNamer` | what it is called there | normalised, unique on the storage |
| `DiskResolver` | which disk | the one from the configuration |
| `QuotaPolicy` | how much room, how much is used | unlimited |
| `UploadValidator` | what is refused, and in which order | deep validation, content first |
| `MediaTypeResolver` | image, video, audio, document | deduced from the MIME type |
| `DuplicateResolver` | what to do with identical content | reuse the existing object |
| `ConversionDriver` | who builds the derivatives | GD, Imagick, or none |
| `UrlGenerator` | the URL of a media | signed and expiring |
| `AccessPolicy` | who may do what | the scope is the boundary |
| `RemoteFetcher` | how a URL is fetched | guarded, and off by default |

### Two more, briefly

**A quota that counts what a tenant already stores:**

```php
final class OrganizationQuota implements QuotaPolicy
{
    public function limitInBytes(?string $scopeKey): ?int
    {
        return Organization::forScope($scopeKey)?->storage_limit;
    }

    public function usedInBytes(?string $scopeKey): int
    {
        return Media::withoutGlobalScopes()->where('scope_key', $scopeKey)->sum('size');
    }

    public function allows(?string $scopeKey, int $incomingBytes): bool
    {
        $limit = $this->limitInBytes($scopeKey);

        return $limit === null || $this->usedInBytes($scopeKey) + $incomingBytes <= $limit;
    }
}
```

⚠️ **The scope key is handed to you rather than read from the request.** That is deliberate:
counting a quota depends on which tenant is being asked about, and a job running on a queue has
no session to ask. Reading the current tenant inside these methods would work in a controller and
quietly count the wrong bytes everywhere else.

⚠️ **And `usedInBytes()` steps outside the global scope on purpose**, because it is told which
scope to count. Leaving the scope applied *and* filtering by the key would work by accident until
the day the two disagree.

**A disk chosen per collection or per tenant:**

```php
final class TenantDisk implements DiskResolver
{
    public function forUpload(array $context): string
    {
        return $context['disk'] ?? config('mediahub.storage.disk');
    }
}
```

⚠️ **`$context` comes from host code, never from a request.** A collection's `onDisk()`, or
whatever you pass to the upload action — a disk name chosen by a client is a way to write into a
disk they were not meant to reach.

---

## Where to put it

Anywhere your conventions say. What matters:

- **One class per contract**, named for what it decides rather than for the package.
- **Bound in a service provider**, not resolved by hand at call sites.
- **Tested**, because every one of these decides something a user will notice.

⚠️ **And none of it belongs in a fork of this package.** If you find yourself editing files under
`vendor/`, something is missing from these contracts — open an issue rather than editing, because
the edit disappears at the next update and takes your reasoning with it.
