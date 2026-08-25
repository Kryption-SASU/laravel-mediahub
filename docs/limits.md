# Limits, and the machine underneath them

A ceiling the runtime refuses is worse than a low one.

`uploads.max_size` set to two hundred megabytes on a PHP whose `post_max_size` is eight does not
accept two hundred: it refuses everything above eight **before a single line of this package
runs**, with an empty body and no reason. Whoever wrote the two hundred reads their own
configuration, sees two hundred, and reports a broken uploader — which is the one bug report
nobody can act on.

The package now says so, and refuses what it believes it cannot finish.

## The health report

```php
// config/mediahub.php
'diagnostics' => [
    'enabled' => env('MEDIAHUB_DIAGNOSTICS', false),
],
```

Off by default, and not because it is dangerous: it reports this machine's PHP limits and which
extensions are loaded — modest information, and still information about the server rather than
about the library. It is meant to be turned on while the package is being set up, read, acted on,
and turned off again. That is also why it is a flag rather than a permission: the person who
needs it is the person editing that file.

⚠️ **The route is not registered when the flag is off**, rather than registered and refusing. A
door that answers 403 tells anybody asking that there is something behind it.

```
GET {prefix}/diagnostics
```

```json
{
  "data": {
    "ok": false,
    "checks": [
      {
        "id": "uploads.post_max_size",
        "level": "error",
        "title": "PHP request size limit (post_max_size)",
        "detail": "PHP refuses requests above 8M, but the library is configured to accept 200M…",
        "recommendation": "Set post_max_size to at least 200M in php.ini, or lower mediahub.uploads.max_size to 8192 (kilobytes)."
      }
    ]
  }
}
```

Every finding names a directive and a value. "post_max_size is too small" sends somebody to a
search engine; the sentence above is a decision they can take in a minute.

It **reports and does not repair**. Nothing writes to `php.ini` or to your configuration, because
a package that quietly raises a limit on its host's server has made a decision that was not its
to make.

### What it looks at

| | |
|---|---|
| `upload_max_filesize`, `post_max_size` | against `uploads.max_size` — and the second bounds the whole request, file plus fields, so it must be larger than the first rather than equal |
| the archive ceiling | against what the time budget allows, saying which of the two fixes applies |
| `zlib.output_compression` | buffering turns streaming into a word rather than a behaviour |
| `memory_limit` | against `uploads.max_image_pixels` — it is the pixels that exhaust memory, not the file size: fifty megapixels is two hundred megabytes to decode, from a file of six |
| `zip`, `fileinfo`, and the image driver's extension | the last only a warning, since storing documents without one is a normal state |

## An archive this machine can finish

An archive that dies halfway has **already sent its 200**. There is no status left to fail with:
the browser saves a ZIP that opens, lists most of its files and is missing the rest. Nothing
anywhere says so — not the log, which records a completed request, and not the person, who has a
file.

`archives.max_files` and `archives.max_bytes` are a **policy**: what you decided to allow. They
say nothing about what the machine can deliver.

```php
'archives' => [
    'max_bytes' => 2147483648,

    /** Seconds a streamed response may really run here. 0 = undeclared. */
    'time_budget' => env('MEDIAHUB_TIME_BUDGET', 0),

    /** Bytes per second the storage is read at, used to turn that budget into a size. */
    'throughput' => 10485760,
],
```

⚠️ **What cuts a long download cannot be read from inside PHP.** `max_execution_time` is largely
beside the point — on Unix it does not count time spent waiting on input and output, which is
nearly all of streaming a remote object store. The real ceilings are PHP-FPM's
`request_terminate_timeout` and the front-end server's proxy timeout, and no code in the process
can see either.

So the budget is **declared, not detected**. Left at zero the package assumes sixty seconds, which
with the throughput above allows roughly 600 MB. That assumption is deliberately modest: the cost
of being wrong is asymmetric — a refused archive is a sentence somebody reads, a truncated one is
a corrupt file found weeks later.

The refusal carries its own reason:

| `reason` | Means |
|---|---|
| `archive_too_large` | the selection exceeds `max_bytes` — a policy you chose |
| `archive_beyond_capacity` | the policy exceeds the machine — the fix is in `php-fpm.conf`, not in the selection |

⚠️ **`max_bytes` of zero is not infinity here.** It means the package imposes no ceiling of its
own; read as "the machine can send anything", it is what lets a two-hour archive start.

### What the stream does for itself

Before writing a byte it turns `zlib.output_compression` off for its own response, lifts its own
time limit, and has ZipStream flush each file as it goes.

⚠️ **What it deliberately does not do is close buffers it did not open.** An output buffer around
the request belongs to whoever opened it — the framework, your middleware, a test runner — and
ending it discards their output as well as freeing ours. Buffering is reported by the health
check instead, where somebody can act on it.
