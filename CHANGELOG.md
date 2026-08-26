# Changelog

Notable changes, newest first. This project follows [semantic versioning](https://semver.org).

⚠️ **`0.x` is not a formality.** Composer treats every minor of a `0.x` package as possibly
breaking, and that is the honest description of this one: the public surface is still moving.
Pin a minor if that matters to you.

## 0.2.0

Work that cannot be done now reports itself instead of ending the process. Three failures met on
a panel-managed host, all of the same shape.

### Breaking

- **`ConversionDriver` gains `needsAProgram(): bool`.** Any driver supplied by a host must
  implement it. The question belongs to the driver: "videos and PDFs need one" is true of the
  drivers shipped here and false the moment a host supplies its own, so no caller can answer it
  from a mime type without answering wrong for somebody.

### Fixed

- **A decode larger than the memory left no longer kills the run.** `imagecreatefromstring` does
  not return `false` when there is no room — the process dies where it stands, and a command
  converting a library stopped on its first oversized file with every later one untouched. The
  size is now read from the header before the decode, and weighed against what is LEFT of
  `memory_limit` rather than against the limit. It is the pixels that cost, not the weight of the
  file: four bytes each, so a fifty-megapixel photograph is two hundred megabytes out of a file of
  six. A library filled before the `uploads.max_image_pixels` ceiling existed meets this; a new
  one does not.
- **A host that forbids `proc_open` no longer turns the health report into a 500.** `new
  Process(...)` throws on its own there, and the construction sat outside the try. The report now
  states the restriction once, at the top, instead of describing four missing tools on a machine
  that has them all.

### Changed

- **A derivative that needs a program is handed to the queue where the request may not run one**,
  and the answer is `202` rather than `200`. On such a host the work is not slower in a request,
  it is impossible — while the same work succeeds on the command line. A `200` would have the
  screen redraw a picture that does not exist yet, which reads as "nothing happened" and invites
  the same click again.

## 0.1.0

The first published version. Everything below exists, is documented and is covered by the test
suite — nothing here is a plan.

### The library

- A folder tree, upload with deep validation, trash and restore, quotas, search and pagination.
- Derivatives built outside the request, on a queue: thumbnails for images, **a frame for a
  video** and **the first page of a PDF**.
- Archives streamed as a ZIP, never written to disk, with a progress figure a screen can show.
- Signed and expiring URLs, and a `MediaScope` contract that makes multi-tenancy a binding rather
  than a feature.

### What it refuses to assume

- **Every point of contact is a contract with a working default.** Scope, quota, access policy,
  disk resolution, path generation, file naming, duplicate handling, URL generation, ownership:
  `composer require` is the whole installation, and a host replaces only what concerns them.
- **It plugs onto existing tables.** The `legacy` preset maps our column names onto a schema that
  already exists, so a library with files in it does not have to be migrated to be adopted.
- **It installs with no image library at all**, and says so instead of failing: a machine with
  neither GD nor Imagick stores and serves files, and draws no thumbnails.

### What it will not do quietly

- **A capability is proven, never advertised.** `Imagick::queryFormats()` answers "yes" for MP4,
  MOV and PDF on machines where every one of them fails; this package tries the format and
  reports what happened.
- **A missing tool is a missing feature, not a failure.** No ffmpeg means videos keep their type
  icon — and the health report says which program is missing, at which path, in which version.
- **An archive that cannot be finished is refused before the first byte.** One cut off halfway has
  already sent its 200: it downloads, opens, and is missing files, with nothing anywhere to say
  so.

### Browser

A Vue 3 library — components, composables and a typed client — and a standalone bundle for an
application with no build step, shipped **on tagged versions only**.

### Requirements

PHP 8.2+, Laravel 12 or 13. `ext-zip` and `ext-fileinfo` are needed; an image library, `ffmpeg`
and a PDF renderer are each optional and each enable one thing.
