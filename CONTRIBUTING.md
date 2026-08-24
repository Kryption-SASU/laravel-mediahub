# Contributing

Thank you for wanting to help. This document explains how to run what already exists, and what
a contribution that can be merged without an investigation looks like.

## Running the suite

```bash
composer install
vendor/bin/phpunit
```

That is all it takes. No Docker, no service to start, no special build of PHP.

The package requires **neither GD nor Imagick**, and neither does its suite: a test that needs
one skips itself when it is absent, and says why. A suite demanding more than the code it tests
would not prove that the code installs anywhere.

### What the pipeline adds

The package promises to install and work whatever image library the host has — or has not. One
machine cannot verify that, so the pipeline runs the same suite across several environments:

| Environment | What it exercises |
|---|---|
| PHP 8.2 / 8.3 / 8.4 × Laravel 12 / 13, **no image extension at all** | portability, and that the package installs |
| GD alone | the GD driver, and Imagick answering "I cannot" |
| Imagick alone | the Imagick driver, and GD answering "I cannot" |
| both | the two side by side |

⚠️ **AND THE AWKWARD PROPERTY IS PROVEN WITHOUT A SPECIAL BUILD.** GD is compiled à la carte: a
build without libjpeg cannot read JPEG even though the extension reports as loaded. A hardcoded
list of formats matching the machine it runs on satisfies any test that merely observes the real
build — catching it used to require a GD stripped of a format the list claimed, which no
pipeline runner has.

`GdConversionDriver` therefore takes its capability source as an argument. A stripped build is
described in one line — `GdCapabilities::of(['PNG Support' => true])` — and the driver's answers
are confronted with it. Those tests run anywhere, including on a host with no GD at all.
Measured: replacing the lookup with a hardcoded list turns four of them red.

**A contribution touching format detection is still asked to say which mutation it ran.**

### The MariaDB gap

The `table` mode adopts an existing schema: unsigned integers, `tinyint`, collations, real
foreign keys. An SQLite mirror reproduces the types and the absences, not their behaviour.
`TableBackendTest` runs against a real MariaDB when `MEDIAHUB_MARIADB` is set — and such a
database is **not reproducible outside the original development environment yet**. A known debt,
written down here so that it is not discovered.

### Coverage

```bash
vendor/bin/phpunit --coverage-clover=coverage.xml
php tools/coverage-gate.php coverage.xml 85
```

This needs a coverage driver — PCOV or Xdebug. The floor is **85%**, on the PHP side and on the
browser side alike. It is not a target to reach later: a pull request that drops below it is
refused by the pipeline.

## What a contribution is expected to carry

**Tests live in the same commit as the code.** Not the next one, not "afterwards". A fix
without a test is a fix that will be made again.

⚠️ **AND THEY HAVE BEEN SEEN RED.** A test written after the code passes on the first run and
proves nothing — it describes what the code does, not what it must do. The simple way to be
sure: deliberately break the line just written, check that the test falls, put it back.

**One mutation per property.** For every guard, every bound, every condition added: replace it
with its opposite and run again. If the suite stays green, the property is not tested — and the
guard is not one. This method found, in this package, an entirely redundant bound, two empty
assertions and three authorisation holes.

**An absence assertion needs a presence assertion beside it.** "The header contains no slash"
is equally true when there is no header at all, because the response is an error page. Always
anchor on what MUST be there.

## Branch names

Three segments — what kind of change, which side, and what it is about:

    feat|fix|refactor|perf|test / back|front / subject
    chore|docs|ci               / subject

```
feat/back/chunked-upload
fix/front/thumbnail-race
ci/pin-setup-php
```

The side says where the change lands before anyone opens it. It is required only where it
means something: a change to the pipeline, the documentation or the licence belongs to
neither, and forcing a choice there would make the answer arbitrary.

Lowercase, no spaces. A branch created from the GitHub web editor is called `patch-1` and is
accepted as such — a one-word correction should not require cloning the repository.

Anything else is refused by the pipeline, which blocks the merge until the branch is renamed.

## Style

**Comments say WHY, never WHAT.** The code already says what it does. What it does not say is
what was measured, what was tried, and what breaks if it is written differently. The ⚠️ marks
the places where the mistake does not raise — the expensive ones, because they are silent.

**No hardcoded value belonging to the host**: no disk name, no path, no table, no string shown
to a user. Every point of contact goes through a contract or through configuration. That is
what makes the package installable elsewhere, and it is the reason the project exists.

**No raw SQL.** The query builder, and arbitration in PHP — including in existing code that is
being touched.

**Commit messages describe the DECISION**, not the diff. What was measured, what was ruled out,
and what the opposite would cost.

## Pull requests

Fork, branch, open a pull request against `main`. The pipeline runs on every pull request,
**with no secret at all** — an outside contribution has to be verifiable in full.

Describe in the pull request: the problem, what was measured, and the mutations that were run.
A pull request saying "fixes a bug" asks for an investigation; one saying "the filter returned
the whole library because `%` was not stripped, measured this way, mutation run" is read in two
minutes.

If an issue exists, link it with `Closes #142` — it is then closed on merge.

## Signing contributions

On your **first** pull request, an automation will ask you to accept the
[contributor agreement](CLA.md). You reply in the thread with the sentence it gives you, your
signature is recorded in this repository, and you will never be asked again.

**You give nothing up.** You keep the copyright on what you write. What the agreement adds to
the project's Apache-2.0 licence comes down to one point: the right, for the holder, to grant
sub-licences. Without it, the project could never change licence again without finding every
one of its contributors — including those who have become unreachable.

⚠️ **If your employer holds rights over what you write**, make sure you have their agreement
before signing. It is the most frequent cause of an invalid agreement, and the hardest to
repair afterwards.
