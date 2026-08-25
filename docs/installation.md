# Installation

> **Status: the package is under construction.** What is described here works today; what does
> not exist yet is marked as such. The form will be reworked later — the substance is written
> alongside the code, so that it says what the code does and not what anyone believes it does.

---

## What you need

| | |
|---|---|
| PHP | **8.2** or later |
| Laravel | **12** or **13** |
| Extensions | `fileinfo`, `json`, `mbstring` |

⚠️ **Laravel 10 and 11 are not supported, and that is not a comfort choice.** Composer refuses
to install them: every one of their versions, including the latest, carries open security
advisories. Supporting them would amount to asking you to disable Composer's security policy in
order to install us.

---

## Installing

```bash
composer require kryption/laravel-mediahub
```

That is all. The package auto-discovers, and **it starts without configuration**: every point
of contact with your application has a default usable as is — no scoping, no quota limit, the
`public` disk.

Check at a glance:

```bash
php artisan about
```

The package appears in the list of loaded providers.

---

## Migrating — and why the order matters

The package **loads its own migrations**; there is nothing to publish for them to run. Which
ones it loads depends on `backend.driver`:

| Driver | What `php artisan migrate` creates |
| --- | --- |
| `standalone` (default) | `mediahub_files`, `mediahub_folders`, `mediahub_conversions`, `mediahub_mediables` |
| `table` | `mediahub_conversions`, and nothing else — your own tables are left alone |
| `table`, with the conversions table set to `null` | nothing at all |

⚠️ **Choose the driver before the first `migrate`.** The default is `standalone`, and the
configuration is not published by itself: until you publish it and say otherwise, the package
believes it owns the schema. So the ordinary order — install, migrate, then configure — runs the
standalone migrations against a host that was going to adopt its own tables.

Three of the four tables that creates are simply never read again, and they are harmless. The
fourth is not, and that is the part worth knowing: **`mediahub_conversions` is needed in both
modes and named the same in both**. In `standalone` it is created with a foreign key onto
`mediahub_files`; under `table` the media live in *your* table, so that key points at rows which
will never exist, and every derivative insert fails — on an upload, days after the migration
that caused it.

So, in order:

```bash
composer require kryption/laravel-mediahub
php artisan vendor:publish --tag=mediahub-config   # decide backend.driver here
php artisan migrate
```

**If it already happened**, nothing is lost. The next `migrate` takes the leftover conversions
table over — it drops it and recreates it in the shape this mode needs — *provided it is
empty*. If it holds rows, it refuses and says so: those rows are the standalone library's
derivatives, and deciding what they are worth is not a migration's job. The three unused tables
are left where they are; drop them yourself once you are sure nothing reads them.

---

## Publishing the configuration

Publish it before you migrate if you are going to run in `table` mode, or at any time if you
only want to change a setting:

```bash
php artisan vendor:publish --tag=mediahub-config
```

The file lands in `config/mediahub.php`. Every setting is commented with **the reason** for its
default, not just its value.

The migrations can be published too, if you want to read them or run them by hand:

```bash
php artisan vendor:publish --tag=mediahub-migrations
```

⚠️ **A published copy shadows ours, silently.** The migrator keys files by name and the
application's own path is read last, so `database/migrations/0001_01_01_000000_create_mediahub_tables.php`
replaces the package's file of that name rather than running beside it — which is what makes
editing a published migration work at all. The price is that the package can never update that
migration again on your installation, and nothing will say so. Publish them to read them, or to
take ownership deliberately; delete the copy when you only wanted a look.

---

## The settings you will touch first

### The disk

```php
'disk' => env('MEDIAHUB_DISK', 'public'),
```

Any disk declared in `config/filesystems.php`: local, S3, Swift, FTP. The package names no
storage in its code.

⚠️ **The disk is recorded on every media.** Changing this setting only affects future uploads:
yesterday's are still served from their original disk, and switching storage does not rewrite
the past.

### Scoping

If your application serves several customers, teams or workspaces, declare your scope:

```php
// app/Providers/AppServiceProvider.php
use Kryption\MediaHub\Contracts\MediaScope;

$this->app->singleton(MediaScope::class, OrganisationScope::class);
```

```php
final class OrganisationScope implements MediaScope
{
    public function currentKey(): ?string
    {
        return ($id = /* your current organisation */) ? 'orgs/'.$id : null;
    }

    public function constrain(Builder $query): Builder
    {
        return $query->where('scope_key', $this->currentKey());
    }
}
```

⚠️ **The key is opaque to the package**: it never interprets it, it files it. That detail has a
practical consequence: if your files are **already** organised in a certain way, a key
reproducing your current paths lets you adopt the package **without moving a single byte**.

⚠️ **And the scope comes from the caller, never from session state.** A door without a session —
a mobile API, an embedded widget — would otherwise record media with no owner, silently and
permanently. That is a defect we have seen cost dearly.

### The quota

By default there is none. To set one, implement `QuotaPolicy`: the package asks you for a limit
and a usage figure, and **adds no column to your tables**.

---

## Living alongside an existing media library

The package is built to **run next to** an already installed media module for the duration of a
transition:

- its tables carry the `mediahub_` prefix — no schema collision;
- it reserves **no alias and no global function**;
- its route prefix is configurable (`mediahub.routes.prefix`);
- it changes **no global setting** of your application, and never touches its configuration at
  runtime.

You can therefore install it, give it a second entry point in your back office, and compare the
two before switching over.

---

## What does not exist yet

This package is still being written. As things stand:

| | |
|---|---|
| ✅ | contracts, defaults, path sanitising, naming |
| ✅ | models, migrations, uploading, derivatives |
| ✅ | HTTP API |
| ⏳ | attaching media to host models |
| ⏳ | Vue 3 interface |

The milestones and their order live in the design notes.


## Thumbnails and the image library

The package **requires no image library**. Without GD or ImageMagick it installs and works:
files are uploaded, filed and served, there are simply no derivatives. That is deliberate — an
intranet that only stores documents should not have to install libwebp in order to use a media
library.

The driver is chosen in the configuration:

```php
'images' => [
    'driver' => env('MEDIAHUB_IMAGE_DRIVER', 'gd'), // 'gd' | 'imagick' | anything else
],
```

| Value | Driver | What it needs |
|---|---|---|
| `gd` *(default)* | `GdConversionDriver` | `ext-gd` |
| `imagick` | `ImagickConversionDriver` | `ext-imagick` |
| any other value | `NullConversionDriver` | nothing |

**The package never switches from one driver to another on its own.** If you ask for `imagick`
on a machine that does not have it, the Imagick driver is what you get, and it answers "I
cannot". A silent fallback to GD would produce different thumbnails from one machine to the
next, and the gap between staging and production would stay unexplainable.

### Formats are not declared, they are observed

Having the extension loaded says nothing about the formats it can read:

- **GD** is compiled à la carte. Without `libwebp`, no WebP; without `libjpeg`, no JPEG; AVIF
  additionally demands `libavif` and PHP 8.1. Two hosts running the same PHP version, with the
  extension loaded in both, do not necessarily read the same files.
- **ImageMagick** depends on its delegates, and PDF additionally depends on Ghostscript **and**
  on `policy.xml` — which most distributions have locked down since the Ghostscript
  vulnerabilities. `queryFormats('PDF')` answers "yes" in both cases, including when reading
  fails.

The package therefore interrogates the host at runtime (`gd_info()`,
`Imagick::queryFormats()`, and for PDF a real, cached round trip) rather than consulting a list
written in the code. When in doubt it answers no: being deprived of a thumbnail leaves the
original viewable, whereas a thumbnail promised and never produced leaves a dead image in a
listing.

To find out what your host can do:

```php
app(\Kryption\MediaHub\Contracts\ConversionDriver::class)->supports('image/webp'); // true|false
```
