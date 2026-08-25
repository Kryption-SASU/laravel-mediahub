# Where the records live

One question decides everything else:

> **Does your application already have a media library?**

If it does not, the package brings its own tables. If it does, the package plugs onto yours —
**without migrating your data, ever.** That second case is why this package exists at all, and it
is the one most likely to be dismissed as impossible.

| Mode | For | Your tables |
|---|---|---|
| **`standalone`** *(default)* | a new library | created by the package |
| **`table`** | an existing one | **untouched, and not copied** |
| **`custom`** | a host with its own storage entirely | never read |

---

## `standalone` — the package brings its own

```php
'backend' => ['driver' => 'standalone'],
```

Four tables appear: `mediahub_files`, `mediahub_folders`, `mediahub_conversions`,
`mediahub_mediables`. The migrations load automatically, so `php artisan migrate` is all it takes.

Nothing to map, nothing to describe. This is the right mode for an application that has no media
library today, and the one to start from if you are unsure.

---

## `table` — the package plugs onto yours

```php
'backend' => [
    'driver' => 'table',
    'preset' => 'legacy',
],
```

⚠️ **No migration of your data. None.** Your rows are not copied, moved, rewritten or
re-numbered. The package reads and writes the tables you already have, through a map that
translates its own column names into yours. Switch the mode back and everything is exactly as it
was, because nothing was ever changed.

That is worth stating plainly because the alternative — export, transform, import, keep the two
in sync during the transition, discover on the third day that a column meant something else — is
what makes most replacements never happen.

### What *is* added, and why

⚠️ **One table, alongside, and only if it is missing.** A legacy media schema usually keeps
thumbnails in a JSON blob on the file row — which the existing screens read, and which cannot
carry a derivative's **state** or its **error**. Without somewhere to record those, a thumbnail
can be built but not tracked, and a single one cannot be regenerated.

```
mediahub_conversions     added if absent
```

The migration checks `hasTable()` first and does nothing when the table is already there. Your own
tables are never in that list.

### What is deliberately *not* added

⚠️ **The linking table for `HasMedia` is not created, and that is a decision rather than an
oversight.** A legacy schema scatters half a dozen `*_media_id` columns across the host
application; replacing them is a separate piece of work, and creating the table now would leave it
empty for weeks while suggesting it is in use.

The consequence is worth knowing before you plan around it: **`HasMedia` has nowhere to write in
`table` mode with the shipped preset.** Everything else works — browsing, uploading, folders,
trash, quotas, derivatives, the screens. Attaching media to your models is what waits.

Adopting it later is one line and one migration:

```php
'tables' => ['mediables' => 'mediahub_mediables'],
```

A table mapped to `null` means **it does not exist in this schema**, and everything that depends
on it knows — rather than failing halfway through an operation.

### The map, and why it was written from the database

The `legacy` preset describes a widely deployed schema **as it exists in the database** — not as
its own migrations describe it. That distinction was not pedantry: on the schema it was measured
against, the migrations were wrong on three counts, including a foreign key that reading them
suggested and that does not exist.

⚠️ **Three traps of such a schema, and none of them raises an error — they lie instead:**

1. **The root is `0`, not `null`.** `folder_id` and `parent_id` are `NOT NULL DEFAULT 0`, so a
   `whereNull()` returns zero rows against a full database — an empty library, with nothing to
   explain it. The preset says `'root_folder' => 0`.
2. **Visibility is a `tinyint`, not a string.** Compared against `'public'` it matches nothing.
3. **Eight columns of the canonical schema do not exist** — `uuid`, `checksum`, `type`,
   `extension`, `file_name`, `width`, `height`, `duration`. Three can be derived on read; the rest
   cannot be kept at all, and whatever depends on them has to know rather than return quietly
   wrong lists.

And the path column is called `url` while holding a **relative path**. That is what makes adoption
possible — this package only ever accepts relative paths — and the name is a leftover from a time
when it did hold an address.

### Overriding the preset

A schema that has drifted by a hair stays adoptable without forking anything: everything can be
overridden on top, column by column.

```php
'backend' => [
    'driver' => 'table',
    'preset' => 'legacy',
    'columns' => [
        'files' => ['name' => 'title'],
    ],
],
```

A column mapped to `null` means **it does not exist in this schema**, and whatever depends on it
knows.

---

## ⚠️ The glue changes with the mode

This is the part that catches people, and it is worth reading before switching.

[The scope](fitting.md) filters on a column. In `standalone` that column is `scope_key`; in
`table` it is whatever your schema calls it — `organization_id`, `tenant_id`, `site_id`. **A glue
that writes the logical name into the query will hit a column that does not exist**, and the
failure arrives as an SQL error on the first screen.

Ask the package instead:

```php
public function constrain(Builder $query): Builder
{
    $column = HostSchema::forMedia()->physical('scope_key');

    if ($column === null) {
        return $query;
    }

    $key = $this->currentKey();

    /*
     * ⚠️ A null key is FILTERED, not ignored. `constrain()` is called unconditionally, so
     * returning the query untouched would open the whole library.
     */
    return $key === null ? $query->whereNull($column) : $query->where($column, $key);
}
```

⚠️ **And the key's shape matters, because it is also what gets written.** The package stamps
`currentKey()` into that column when it creates a record. A composite key like `orgs/42` does not
fit an integer `organization_id`: MySQL stores `0` without complaining — that is, in nobody's
scope. In `table` mode the key is usually the raw identifier.

---

## `custom` — the package touches no database

```php
'backend' => ['driver' => 'custom', 'adapter' => YourAdapter::class],
```

For a host whose media do not live in a table this package could read at all. It loads no
migrations and makes no assumption about a schema; you supply the reads and writes.

---

## Moving between modes

No data moves, because the mode decides **which tables are read**, not where anything goes.

- **`standalone` → `table`**: the package stops reading its own tables and starts reading yours.
  Anything already uploaded through it stays in `mediahub_files`, invisible until you switch back
  or move those rows yourself.
- **`table` → `standalone`**: your tables are left exactly as they were.

⚠️ **But run `migrate` after the switch, because one table is shared.** `mediahub_conversions` is
needed in both modes and named the same in both — it is the only one that does not simply fall out
of use. Its shape differs: `standalone` gives it a foreign key onto `mediahub_files`, and under
`table` the media live in yours, so that key points at rows which will never exist and every
derivative insert fails. The migration takes the table over on the next run *if it is empty*, and
refuses with an explanation if it holds rows — those are the standalone library's derivatives, and
what they are worth is not a migration's decision.

The tables that do fall out of use — `mediahub_files`, `mediahub_folders`, `mediahub_mediables`
going one way, nothing going the other — are left where they are. Nothing reads them, nothing
cleans them up, and dropping them is yours to do once you are sure of it.

⚠️ **What does not move on its own is the files on the storage.** Both modes write to the disk
named in the configuration; changing the mode does not relocate a single byte, and it is not
supposed to.
