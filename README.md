# MediaHub

**A multi-tenant media library for Laravel.** Folder tree, trash, quotas, derivatives, streamed
archives, signed and expiring URLs — and no opinion about where your files belong.

[![CI](https://github.com/Kryption-SASU/laravel-mediahub/actions/workflows/ci.yml/badge.svg)](https://github.com/Kryption-SASU/laravel-mediahub/actions/workflows/ci.yml)

```bash
composer require kryption/laravel-mediahub
```

That is the whole installation. Every point of contact with your application has a default that
works as it is: no scoping, no quota, the `public` disk. You replace only what concerns you.

> **Status: under construction.** Everything documented here exists and is covered by the test
> suite. What is missing is listed in [Roadmap](#roadmap) — nothing is promised that is not
> built.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or later |
| Laravel | 12 or 13 |
| Extensions | `fileinfo`, `json`, `mbstring` |

GD and ImageMagick are **optional**. Without either, the package installs and works: files are
uploaded, filed and served — there are simply no thumbnails.

Laravel 10 and 11 are not supported: Composer refuses to install them, every version carrying
open security advisories.

---

## What it does differently

**You decide where files land.** The package sanitises the folder you give it and stops there.
How media are organised belongs to your trade — an agency files by client and campaign, an
intranet by department, a messaging product by thread. What it keeps for itself is what nobody
wants to write twice: path traversal closed off, and derivatives filed next to their original.

**Scoping is a global scope, not a `where` clause you have to remember.** Declare a
`MediaScope` and every read and every write is bounded by it — including the screen somebody
writes a year from now.

**A path is never a URL.** The database keeps a disk and a relative path; the URL is computed
when serving. You can change storage, move behind a cache or turn signing on without migrating a
single row.

**No disk name, no table, no route is hardcoded.** The package writes nothing into tables it
does not own, reserves no global alias or function, and never changes your application's
configuration at runtime.

**Media attach to your models through one relation.** `HasMedia` replaces the `*_media_id`
column that every model grows on its own — and `addExistingMedia()` attaches a file the user
already has, which an upload field cannot do at all.

**It can adopt an existing schema.** In `table` mode it maps onto tables you already have —
renamed columns, missing columns, `0` instead of `NULL` for "no parent" — without the rest of
your code knowing.

**Everything streams.** Uploads, downloads, `Range` requests for video, and multi-file archives
go through `readStream`/`writeStream`. Nothing loads a whole file into memory, and no temporary
archive is ever written to disk.

---

## Quick start

### Uploading

```php
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

$media = app(UploadMedia::class)(
    UploadedPayload::fromUploadedFile($request->file('photo')),
    ['directory' => 'clients/acme/winter-campaign'],
);
```

The payload has four entrances — an uploaded file, a local path, a Laravel disk, a stream — and
all four go through the same validation, quota check, naming and write.

### Serving

```php
use Kryption\MediaHub\Contracts\UrlGenerator;

$urls = app(UrlGenerator::class);

$urls->url($media);            // signed, expiring, direct from the storage when it can sign
$urls->downloadUrl($media);    // attachment, under the displayed name
```

### Browsing

```php
use Kryption\MediaHub\Actions\BrowseMedia;
use Kryption\MediaHub\ValueObjects\BrowseQuery;

$page = app(BrowseMedia::class)(BrowseQuery::fromInput($request->all(), $folder));
```

Sorting is an allow-list, the page size is capped, and every listing carries a tie-breaking
second sort so no item shows up on two pages.

### HTTP

The package ships its own routes — browse, upload, folders, trash, archive, serve, download —
inside the middleware group you choose. One route per operation, never a single entry point with
an `action` field.

```php
'routes' => [
    'prefix' => 'media',
    'middleware' => ['web', 'auth'],
],
```

An `api` group can be enabled alongside it, from the same route file, under its own prefix and
middleware.

---

## Attaching media to your models

One polymorphic relation instead of a `*_media_id` column per case:

```php
use Kryption\MediaHub\Concerns\HasMedia;
use Kryption\MediaHub\Support\MediaCollections;

class Post extends Model
{
    use HasMedia;

    public function registerMediaCollections(MediaCollections $collections): void
    {
        $collections->add('cover')->single()->accepts('image/*')->maxSize(4096);
        $collections->add('attachments');
    }
}
```

```php
$post->addMedia(UploadedPayload::fromUploadedFile($request->file('cover')), 'cover');

// ...or attach something the user already has, which is the point of a library
$post->addExistingMedia($media, 'attachments');

$post->getMedia('attachments');        // in the order they were arranged
$post->getFirstMediaUrl('cover');      // the declared fallback when there is none
$post->syncMedia([$a, $b], 'attachments');
$post->removeMedia($a, 'attachments'); // detaches; the file stays in the library
```

**`addExistingMedia()` is what an upload field cannot do.** Attaching a file the user already
owns costs one row and no bytes — no re-upload, no second copy, nothing extra to delete later.

A collection is a rule, not a folder: `single()`, `accepts()`, `maxSize()`, `onDisk()`,
`fallback()`. The rules are checked **on the real type and before the bytes are written**, and a
collection nobody declared is unconstrained rather than refused.

---

## Fitting it to your application

Every point of contact is a contract with a working default. Bind only what you need — **your
binding always wins**, whatever the order service providers boot in.

| Contract | What it decides | Default |
|---|---|---|
| `MediaScope` | what partitions the library | no scoping |
| `PathGenerator` | where a file lands | the folder you give, sanitised |
| `FileNamer` | the name on disk | normalised, unique on the storage |
| `DiskResolver` | which disk | the one from the configuration |
| `QuotaPolicy` | how much room, how much is used | unlimited |
| `UploadValidator` | what is refused, and in which order | deep validation, content first |
| `MediaTypeResolver` | image, video, audio, document | deduced from the MIME type |
| `DuplicateResolver` | what to do with identical content | reuse the existing object |
| `ConversionDriver` | who builds the derivatives | GD, Imagick, or none |
| `UrlGenerator` | the URL of a media | signed and expiring |
| `AccessPolicy` | who may do what | the scope is the boundary |

### Scoping, in full

```php
use Illuminate\Database\Eloquent\Builder;
use Kryption\MediaHub\Contracts\MediaScope;

final class OrganisationScope implements MediaScope
{
    public function currentKey(): ?string
    {
        return ($id = currentOrganisationId()) ? 'orgs/'.$id : null;
    }

    public function constrain(Builder $query): Builder
    {
        return $query->where('scope_key', $this->currentKey());
    }
}
```

```php
$this->app->singleton(MediaScope::class, OrganisationScope::class);
```

The key is opaque: the package files it and bounds queries with it, and never interprets it.
`null` is a valid answer — a single-tenant product has no scope, and even elsewhere some files
belong to nobody in particular.

---

## Security posture

- **Uploads are validated on their content**, never on what the client declares. Size,
  extension allow-list, real MIME type read from the first bytes, agreement between the two,
  image dimensions capped **before** decoding, and SVG refused as the executable document it is.
- **Identifiers exposed are route keys**, not database keys, so listings cannot be enumerated.
- **"Not found" and "not yours" are the same answer.** Telling them apart is an enumeration
  oracle.
- **A batch is authorised whole, before the first write.** Authorising as you go leaves half-run
  operations behind.
- **Signed URLs actually expire.** When signing is requested and no path can deliver it, the
  package raises rather than quietly handing back a permanent public link.
- **The pipeline uses no secret at all**, which is what keeps it verifiable for contributions
  coming from a fork.

Reporting a vulnerability: see [SECURITY.md](SECURITY.md). Please do not open a public issue.

---

## Adopting an existing schema

Point the package at tables you already have, and the rest of your code will not notice:

```php
'backend' => [
    'driver' => 'table',
    'preset' => 'legacy',
    'map' => [
        'files' => ['path' => 'url'],  // override the preset, column by column
    ],
],
```

A logical column may carry the same name, another name, or **not exist at all** — the third case
is the one that shapes the design. What cannot be stored is derived on read where that makes
sense, and refused for filtering where it does not: an accessor is not an index.

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

Nothing else to install and nothing to start. A test that needs GD or Imagick skips itself when
it is absent and says why — the same way the package does.

The package promises to work **with or without** an image library, and one machine cannot verify
that. The pipeline therefore runs the same suite across PHP 8.2 / 8.3 / 8.4 and Laravel 12 / 13
with **no image extension at all**, then again with GD alone, with Imagick alone, and with both.
That is how the promise is held rather than asserted.

Format detection is proven rather than observed. `GdConversionDriver` takes its capability
source as an argument, so a GD stripped of JPEG and WebP is described in one line and the
driver's answers are confronted with it — on any machine, including one with no GD at all.

Coverage floor: **85%**, enforced by the pipeline.

The browser side is held to the same floor, with `npm run types` and `npm run test:coverage`.
Its types are not merely declared: the fixtures they are checked against are **written by the PHP
suite from real responses** and committed, so the server cannot change the shape of a payload
without turning a test red on both sides. See [the browser side](docs/browser.md).

---

## Roadmap

| | |
|---|---|
| ✅ | contracts, defaults, path sanitising, naming |
| ✅ | models, migrations, uploading, derivatives, trash |
| ✅ | HTTP API, streamed serving and archives, signed URLs |
| ✅ | attaching media to host models — `HasMedia`, collections, `addExistingMedia()` |
| ✅ | the browser core — typed client, upload queue, no framework |
| ✅ | Vue 3 composables — browsing, selection, uploading, actions, picking |
| ✅ | the theme mechanism, the provider, and the primitives |
| ⏳ | `addMediaFromUrl()` — deliberately held back, see below |
| ⏳ | per-collection derivative definitions |
| ✅ | the picker, and media in a form — MhMediaInput, MhMediaGallery |
| ✅ | actions, uploading, quota and details |
| ✅ | the full library screen — `MhMediaLibrary` |

⚠️ **`addMediaFromUrl()` is missing on purpose.** Fetching a URL the server is handed is a
request-forgery primitive: without a guard it reaches internal addresses, cloud metadata
endpoints and anything else the host can see. It will arrive with that guard, or not at all.

---

## Documentation

- [Installation](docs/installation.md)
- [Usage](docs/usage.md)
- [The browser side](docs/browser.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) first — it covers the branch
naming the pipeline enforces, the environments the pipeline covers, and what a pull request is
expected to carry. On your first one, an automation will ask you to accept the
[contributor agreement](CLA.md); you keep the copyright on what you write.

## Licence

[Apache-2.0](LICENSE). See [NOTICE](NOTICE).
