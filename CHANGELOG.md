# Changelog

Notable changes, newest first. This project follows [semantic versioning](https://semver.org).

⚠️ **`0.x` is not a formality.** Composer treats every minor of a `0.x` package as possibly
breaking, and that is the honest description of this one: the public surface is still moving.
Pin a minor if that matters to you.

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
