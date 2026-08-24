# Usage

> **Status: the package is under construction.** This document grows with it: every section
> describes what exists **and works**, and flags what is not there yet. The form will be
> reworked later.

---

## The ideas to keep in mind

Three notions are enough to understand everything else.

### The scope

This is what partitions the library: an organisation, a team, a workspace. The package knows
nothing about yours — you give it a **key**, it files it and uses it to bound what it shows.

**There may be none**, and that is not an error case: a single-tenant product never has one, and
even elsewhere some files belong to nobody in particular. They then have a place of their own,
not a rejection.

### The path — and who decides it

**You do.** The package files nothing: it takes the folder you give it, sanitises it, and stops
there.

⚠️ **This is the package's most important point, and it is a negative property.** How media are
organised belongs to your trade. An agency files by client and campaign, an intranet by
department, a messaging product by thread, a brochure site by year. Imposing a tree would amount
to imposing a trade — and would force you to work around the package at the first special case.

What the package does keep is what everybody needs and nobody wants to write again: **path
traversal closed off**, and **the derivative that follows its original**.

And the path is **decided on write, then recorded** — never recomputed on read. Renaming a
folder therefore moves no file.

It is **never a URL**: the database keeps only a disk and a relative path. The URL is computed
at serving time, which makes it possible to change storage, to move behind a cache or to turn
signing on without migrating a single row.

---

## Building a path

```php
use Kryption\MediaHub\Contracts\PathGenerator;

$directory = app(PathGenerator::class)->directory([
    'directory' => 'clients/durand/winter-campaign',
]);

// clients/durand/winter-campaign/
```

Another company, another way of filing, the same factory:

```php
app(PathGenerator::class)->directory(['directory' => '2026/08/invoices']);
// 2026/08/invoices/
```

A shared leading folder, if you want one:

```php
$this->app->bind(PathGenerator::class, fn () => new DefaultPathGenerator('media'));
// media/2026/08/invoices/
```

### What the factory guarantees regardless

**No segment climbs out of a folder** — this is where, and nowhere else, path traversal is
closed off, because this is where a path is built:

```php
app(PathGenerator::class)->directory(['directory' => '../../../etc/passwd']);
// etc/passwd/   — no segment climbs
```

**We sanitise without transforming**: dangerous characters disappear, accents and capitals stay.
Normalising beyond what is necessary would impose a taste, and would make the path you asked for
unpredictable.

### Derivatives

A thumbnail is filed **next to its original**:

```php
app(PathGenerator::class)->conversion('invoices/photo.jpg', 'thumb');
// invoices/photo-thumb.jpg
```

⚠️ **This is not an aesthetic preference.** Some readers derive that path by string manipulation
from the original's: filing them elsewhere would break those readers, and they are often out of
your sight.

### Going further

Want families, a root per client, a tree by date, a rule that depends on the file type?
Implement `PathGenerator`: that is the door provided for it, and the package will never ask you
why.

---

## Naming a file

Two names coexist, and confusing them is a mistake:

- **the displayed name**, the one the person sees and can change;
- **the name on disk**, normalised, without accents or spaces.

```php
use Kryption\MediaHub\Contracts\FileNamer;

$namer = app(FileNamer::class);

$namer->sanitize('Annual Report 2026.pdf');   // annual-report-2026
$namer->unique('report.pdf', 'media', 'invoices/2026');   // report-1.pdf if the first exists
```

⚠️ **The name is normalised, the folder is not — and that is not an inconsistency.** The name
comes from a **client**: it is an arbitrary string, often typed in, sometimes an attack. The
folder comes from **your code**: you decided it, the package respects it. What crosses the
boundary is normalised; what comes from your side is not.

⚠️ **Uniqueness is checked against the storage**, not against a local filesystem. That is a
defect we have seen for real: a module checked existence with a local function while its objects
lived on remote storage — the check therefore never detected anything, and two uploads of the
same name silently overwrote the first object, while creating two rows naming the same path.

⚠️ **A name with nothing to normalise never produces an empty name.** Again from experience:
files ending in a dot, with no extension, unfindable forever.

---

## Fitting the package to your application

Every point of contact is a contract, with a default value. You replace only what concerns you,
in your `AppServiceProvider`:

| Contract | What it decides | Default |
|---|---|---|
| `MediaScope` | what partitions | no scoping |
| `PathGenerator` | where a file lands | **the folder you give**, sanitised |
| `FileNamer` | the name on disk | normalised, unique |
| `DiskResolver` | which disk | the one from the configuration |
| `QuotaPolicy` | how much room | unlimited |
| `MediaTypeResolver` | image, video, audio, document | deduced from the MIME type |
| `DuplicateResolver` | what to do with a duplicate | reuse the existing object |
| `UploadValidator` | what is refused, and in which order | deep validation, content-first |
| `ConversionDriver` | who builds the derivatives | GD, or Imagick, or none |
| `UrlGenerator` | the URL of a media | signed and expiring |
| `AccessPolicy` | who may do what | the scope is the boundary |
| `MetadataExtractor` · `ExternalProvider` | ⏳ to come | |

⚠️ **Your binding always wins.** The package only binds a contract when it is not already
resolved: the boot order of service providers cannot take the decision away from you.

---

## Attaching media to your models

Declare what a model accepts, then attach:

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
        $collections->add('avatar')->single()->fallback('/img/anonymous.png');
    }
}
```

⚠️ **THE REGISTRAR ARRIVES AS AN ARGUMENT, and that is not a matter of style.** A registrar
handed in can be built, filled and read without the model being involved: a screen can ask what
a model accepts before showing a file input, and a test can double it. Methods called on `$this`
send every one of those through an instance for no reason.

### What a collection is

A **rule**, not a folder. It says what may be attached under a name and how many; it says
nothing about where the bytes are filed — that is the `PathGenerator`'s business, and conflating
the two is what turns moving a file into a migration.

| | |
|---|---|
| `single()` | one at a time; a second one **replaces** the first rather than being refused |
| `accepts()` | exact types (`application/pdf`) or families (`image/*`) |
| `maxSize()` | in kilobytes, like `uploads.max_size` |
| `onDisk()` | where new uploads for this collection go |
| `fallback()` | the URL `getFirstMediaUrl()` returns when there is nothing |

⚠️ **A COLLECTION NOBODY DECLARED IS UNCONSTRAINED, NOT REFUSED.** The package starts without
configuration here as everywhere else, and the name of a collection is not a security boundary —
the scope is.

⚠️ **AND THE RULES ARE CHECKED ON THE REAL TYPE, BEFORE THE BYTES ARE WRITTEN.** Checking
afterwards would mean deleting what was just stored; checking the declared extension would let
`accepts('image/*')` through for an executable document renamed `.png`.

### Attaching

```php
// an upload
$post->addMedia(UploadedPayload::fromUploadedFile($request->file('cover')), 'cover');

// something already in the library — one row, no bytes
$post->addExistingMedia($media, 'attachments');
```

⚠️ **`addExistingMedia()` TAKES A MODEL, NOT A KEY.** A key received from a client would have to
be resolved here, and resolving it here is how an identifier belonging to somebody else gets
attached to your own record. The caller resolves it, through the scope, like everywhere else.

### Reading, ordering, removing

```php
$post->getMedia('attachments');        // in the order they were arranged
$post->getFirstMedia('cover');
$post->getFirstMediaUrl('avatar');     // the fallback when the collection is empty
$post->hasMedia('cover');

$post->syncMedia([$a, $b], 'attachments');   // exactly these, in this order
$post->removeMedia($a, 'attachments');
$post->clearMediaCollection('attachments');
```

⚠️ **DETACHING IS NOT DELETING.** The file belongs to the library, not to the model that
referenced it — including when a `single()` collection replaces its occupant.

⚠️ **AND EACH OPERATION IS BOUNDED TO ITS COLLECTION.** A plain `sync()` on the relation would
drop everything attached under every other name: replacing a cover would take the attachments
with it, and nothing would say so.

### In `table` mode

An adopted schema usually has no linking table. Touching the relation then raises
`StorageMisconfigured` rather than querying a table that is not there — declare
`backend.tables.mediables`, or do not use the trait on that installation.

---

## What does not exist yet

**`addMediaFromUrl()`**, and the browser interface.

⚠️ **THE FIRST IS HELD BACK ON PURPOSE.** Fetching a URL handed to the server is a
request-forgery primitive: without a guard it reaches internal addresses, cloud metadata
endpoints, and anything else the host can see from the inside. It will arrive with that guard,
or not at all — a convenience method is not worth an outbound hole.

**Per-collection derivative definitions** are also absent: a media can sit in two collections,
so "which definitions apply" needs an answer before the feature, not after.


## Asking for a thumbnail

`supports()` is a **promise**: if it answers `true`, `convert()` produces a derivative; if it
answers `false`, `convert()` raises. There is no third case — in particular, never an empty file
handed back as though it were a thumbnail.

```php
$driver = app(\Kryption\MediaHub\Contracts\ConversionDriver::class);

if ($driver->supports($media->mime_type)) {
    $driver->convert($media->disk, $media->path, $target, ['width' => 256, 'height' => 256, 'fit' => 'cover']);
}
```

`fit` accepts `cover` (fills the frame and crops, for a regular listing) or `contain` (fits
inside the box without cutting anything, for a preview).

An unsupported format **is not an error**: the original is still uploaded, filed and served.
Every reader must therefore allow for the absence of a derivative, including for files that have
one elsewhere — the same library deployed on two machines does not necessarily produce the same
ones.


## Videos, audio, documents: stored as they are

**Nothing you upload is transformed.** The original is never resized, re-encoded or
recompressed — whatever its type, and even when a thumbnail is built alongside it. Derivatives
are **extra** files. A media library that "optimises" what it is entrusted with destroys without
saying so, and nobody notices before they need the original.

A **video produces no derivative** — not even a "failed" row. Extracting a frame from a video
requires an external tool (ffmpeg) this package does not require; recording a failure would
display an error state for something that was never attempted, and would send someone looking
for a failure that does not exist. There was nothing to do.

On the display side it is therefore up to the interface to show a placeholder according to the
type (`$media->mediaType()`), as any media library without ffmpeg does.

### The accepted formats

| Nature | Extensions |
|---|---|
| Images | `jpg` `jpeg` `png` `gif` `webp` `avif` `bmp` `heic` `heif` |
| Video — Apple | `mov` `m4v` `mp4` |
| Video — Android | `3gp` `3g2` `webm` `mkv` |
| Video — Windows | `avi` `wmv` `asf` |
| Video — others | `flv` `ogv` `mpg` `mpeg` `ts` `m2ts` `mts` (AVCHD) |
| Audio | `mp3` `wav` `ogg` `oga` `m4a` `aac` `flac` `wma` |
| Documents | `pdf` `txt` `csv` `doc` `docx` `xls` `xlsx` `ppt` `pptx` `zip` |

An iPhone films in QuickTime, an Android in 3GPP or WebM, a camcorder in AVCHD, Windows in ASF:
accepting only MP4 amounts to refusing half of what people have in their hands, and an upload
refusal is never explainable to the person on the receiving end.

**Two traps are worth knowing**, because they defeat naive implementations:

- a `.wma` is an **ASF** container, the same as a `.wmv`: `finfo` answers `video/x-ms-asf` for a
  purely audio file. A rule "audio extension ⇒ audio type" therefore refuses every WMA. Here the
  extension only serves to **break a tie** between two natures the content does not distinguish
  — it never decides on its own;
- AVCHD (`.ts`, `.m2ts`, `.mts`) is recognised as `video/MP2T`, **in capitals**.

### What stays refused, and why

Widening an allow-list is the moment a hole gets reopened. Three checks stay in place, in this
order: the extension allow-list, the **real type read from the content** (never the one the
client declares), and the **confrontation of the two**. An SVG or an HTML page uploaded under a
video name are refused — they are executable documents, and served inline from your domain they
run in your users' browsers.

The accepted families contain neither `text` nor `application`: that is where the check holds.
Widening to multimedia formats changes nothing there.

### The size ceiling

`uploads.max_size` has been raised to **200 MB**: a minute filmed on a phone goes well past the
previous 8 MB, which would in practice have refused everything above.

⚠️ **This is not the real ceiling.** `upload_max_filesize`, `post_max_size` and your front-end
server's limit apply **before** it, and are lower by default. This value is a bound the package
imposes on itself, not a permission it grants.


## HEIC, and the decoder's bounds

HEIC is the default format of **every iPhone photo**. It is accepted.

That was not free: `getimagesize()` cannot open a HEIC, and the guard against decompression
bombs refused everything it could not measure — therefore an entire mobile estate. The rule is
now in three steps:

1. `getimagesize()` measures what it knows, on the header, without decoding anything;
2. for the formats it ignores — HEIC and HEIF, a short and closed list — the measurement goes
   through an `Imagick::pingImage()`, which also reads the header only;
3. if the ping fails, **nothing on this host can open this file** — therefore nothing will
   expand it in memory, and the upload is accepted. The danger only exists if something decodes.

For any other image format, an unreadable header remains a **refusal**: `getimagesize()` can
read PNGs, so its failure on a PNG is a signal, not a gap.

### `images.limits` — what actually bounds ImageMagick

```php
'limits' => [
    'max_side'  => 20000,  // px, width AND height — the only one that refuses
    'memory_mb' => 256,
    'map_mb'    => 512,
    'disk_mb'   => 1024,
    'seconds'   => 30,     // 0 = no limit, in ImageMagick
    'threads'   => 1,
],
```

Three things to know, all verified on the bench rather than assumed:

- **the memory limits refuse nothing.** `MEMORY`, `MAP` and `AREA` only decide *where* the
  pixels are cached: once exceeded, ImageMagick spills to disk and carries on. A 4000×4000 image
  goes through under a one-kilobyte limit. It is `max_side` that stops a bomb, **before any
  allocation**;
- **these bounds are process-wide.** ImageMagick does not attach them to an instance: setting
  them changes the behaviour of everything using it in your application. That is accepted — a
  protection that only held for our own calls would not be one. The package therefore sets them
  again before every operation rather than trusting whatever is left over;
- **they only concern ImageMagick.** GD is already bounded by `uploads.max_image_pixels`, which
  is checked against the header.

### "Advertised" is not "available"

`Imagick::queryFormats()` lists the formats ImageMagick *knows about*, not the ones it can open.
Three cases met on a single bench:

| Format | Advertised | Usable | Why |
|---|---|---|---|
| `PDF` | yes | **no** | Ghostscript absent — and often forbidden by `policy.xml` |
| `HEIC` | yes | **no** | no working libheif |
| `AVIF` | yes | **no** | likewise |

The package therefore does not trust that list: it **confirms every format with a real round
trip** — build an 8×8 in that format, then read it back — once per format per process. A
delegate that can neither write nor read is an absent delegate.

That probe can be wrong in one direction, and it is the right one: a build that could *read* a
format without being able to *write* it will be declared incapable. We lose a thumbnail, we
promise nothing false, and the original is still served.
