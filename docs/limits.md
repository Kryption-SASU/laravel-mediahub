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
| which PHP is answering | `fpm-fcgi`, `apache2handler`, `cgi-fcgi`… — every sentence about a timeout below is chosen from it |
| `upload_max_filesize`, `post_max_size` | against `uploads.max_size` — and the second bounds the whole request, file plus fields, so it must be larger than the first rather than equal |
| `max_execution_time` | against whether `set_time_limit` is still callable — see [below](#the-one-limit-classic-php-actually-hits) |
| the cache store | whether two requests can meet in it. `array` and `null` cannot, so no download can be watched — nothing breaks, and nothing says why either |
| `ffmpeg`, `ffprobe`, and a PDF renderer | the **resolved path** and the version of each, or which of the two absences it is — see below |
| what ImageMagick can **actually** read | proven format by format, never read from `queryFormats()` |
| the archive ceiling | against what the time budget allows, saying which of the two fixes applies |
| `zlib.output_compression` | buffering turns streaming into a word rather than a behaviour |
| `memory_limit` | against `uploads.max_image_pixels` — it is the pixels that exhaust memory, not the file size: fifty megapixels is two hundred megabytes to decode, from a file of six |
| `zip`, `fileinfo`, and the image driver's extension | the last only a warning, since storing documents without one is a normal state |

## The programs a thumbnail depends on

⚠️ **No PHP extension here can draw a video or a PDF, and the one that claims to is lying.**
`Imagick::queryFormats()` announces `MP4`, `MOV` and `PDF`. The video formats go through a
**delegate** — which is ffmpeg itself — and distributions cut every delegate in `policy.xml`;
the PDF coder is cut outright. Measured on two machines, and it is why the report proves formats
by trying them rather than by asking.

```php
'tools' => [
    'ffmpeg'  => env('MEDIAHUB_FFMPEG'),   // null: go and look
    'ffprobe' => env('MEDIAHUB_FFPROBE'),
    'pdf'     => env('MEDIAHUB_PDF'),      // pdftoppm, or gs
],
```

**Null means "go and look", a path means "use exactly this".** ⚠️ A configured path that is not
an executable file is **not** quietly replaced by whatever is on the `PATH`: the report says the
configured one is unusable. Falling back would run a different program than the one that was
named and say nothing about it — the host goes on believing their setting is in force, and the
version on their screen is somebody else's.

That is also why the report shows the **resolved path**, not merely "found": a host with three
ffmpegs has exactly one question, and a yes/no cannot answer it.

⚠️ **`pdftoppm` is preferred over `gs` when both are present.** Ghostscript is a complete
PostScript interpreter — a language with loops and file access — which is what earned ImageMagick
its worst vulnerabilities, to the point where Debian still ships `<policy domain="coder"
rights="none" pattern="PDF" />` today, on a package thirteen security revisions past the version
it names. `pdftoppm` only ever draws pages. Ghostscript is still accepted: refusing it would help
nobody who already has it.

⚠️ **Nothing is run through a shell.** Arguments are handed to the kernel one by one, so a file
name holding a space, a quote or a semicolon is a file name and nothing else. `escapeshellarg`
appears nowhere in this package — its presence would mean a command line existed to escape into.

⚠️ **And every run is bounded in time.** A program given a malformed file can sit for ever, and a
request that never returns holds a worker until the pool manager kills it — a far more expensive
failure than a missing thumbnail.

### What they draw

```php
'video' => [
    /** The second to capture. Zero is the wrong answer — see below. */
    'frame_at' => 3,
],
'tools' => [
    /** How much will be pulled down for a thumbnail. 0 = no ceiling. */
    'max_source_bytes' => 209715200,
],
```

⚠️ **Zero seconds is the wrong frame.** Films fade in, phone recordings start on a lens cap or a
ceiling, screen captures on an empty desktop: a library thumbnailed at zero is a grid of black
squares, which is worse than the type icon it replaced.

⚠️ **And a capture past the end produces nothing at all, silently.** ffmpeg seeks, finds no frame,
writes no file and exits without complaint. The length is read first and the request brought
inside it — that is what ffprobe is for here, beyond the report.

⚠️ **A video type is not a promise of a picture.** `.wma` is an ASF container, the same as `.wmv`,
so `finfo` answers `video/x-ms-asf` for a purely audio file. There is nothing to draw, and the
pending row is **removed** rather than marked failed: a failure signals a fault, and sends
somebody looking for one that does not exist.

⚠️ **The PDF page is never cropped**, even though the definition asks for `cover`. A document is
recognised by its head — the letterhead, the title — and a square crop of a portrait page removes
exactly that.

⚠️ **The source is pulled down to a local file, streamed, and bounded.** A program reads a path
and our bytes live on object storage; `$storage->get()` on a five-hundred-megabyte video is five
hundred megabytes of PHP memory. Past `max_source_bytes` there is no thumbnail rather than a
transfer nobody asked for — the file still uploads, downloads and plays.

⚠️ **And ffmpeg is never handed an address.** It speaks http, rtmp and a dozen other protocols,
and a crafted file can name another input; `-protocol_whitelist file` is what stops that being
followed. Reading from a URL would be faster and would let a program with a long history of
parser flaws make requests on our behalf.

### Files that were already there

Derivatives are made once, at upload. A library that predates the tool drawing its thumbnails has
none for anything already in it, and the alternative was uploading every file a second time —
doubling the storage and changing every identifier.

```bash
php artisan mediahub:conversions --missing
php artisan mediahub:conversions --type=video --queue --limit=50
```

⚠️ **`--missing` skips what already has a READY one.** A row left at `failed` or `pending` is
exactly what somebody running this wants to retry; counting it as done would make the flag
useless on the files it was written for.

⚠️ **And what nothing here can draw is named, not swallowed.** "Nothing can be drawn for these"
is the answer to the question asked next — why is that folder still all icons — and a silent skip
sends somebody to the source instead. It is counted by type, once, at the end.

The same thing for one file is `POST {prefix}/{media}/conversions`, which the context menu uses.
⚠️ **It is done now rather than queued**: the screen shows a spinner on the tile and wants to draw
the answer when it lets go, and a job handed to a worker would make the entry look broken. The
work is bounded — the source has a ceiling and each program has a timeout.

### Asking a program its version is not obvious

There is no agreed flag, and **the exit code does not settle it**. Measured: given `-version`,
`pdftoppm` takes it for a file name, prints `I/O Error: Couldn't open file '-version'` — and
exits **zero**. So the flags are tried in turn and the answer is judged on its content: a version
line carries a version. Shipped without that check for an afternoon, the report showed the error
message where the version belonged.

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
beside the point as a wall clock — it does not count time spent waiting on input and output, which
is nearly all of streaming a remote object store. Measured here: a script blocked on a pipe
outlived a two-second limit by fifteen, while the same limit killed a busy loop at 2.1 seconds.

⚠️ **And the ceiling that does apply is not the same setting on every machine.** This is the part
that has to be right, because advice naming a file somebody does not have reads as a report about
a different product:

| PHP runs as | `PHP_SAPI` | What really ends a long request |
|---|---|---|
| PHP-FPM | `fpm-fcgi` | the pool's `request_terminate_timeout`, and the proxy timeout in front of it |
| an Apache module | `apache2handler` | **nothing bounds the duration** — mod_php has no such setting, and Apache's `Timeout` fires on a stalled connection rather than a slow one. What is left is your CDN or reverse proxy, if any |
| FastCGI / CGI | `cgi-fcgi` | nginx's `fastcgi_read_timeout`, or `FcgidIOTimeout` under mod_fcgid — sixty seconds by default either way |
| the command line | `cli` | nothing, which is why a report produced from a console says so about itself |
| anything else | — | LiteSpeed, FrankenPHP, RoadRunner: the package says it does not recognise the runtime rather than guessing at a file name |

So the budget is **declared, not detected**. Left at zero the package assumes sixty seconds, which
with the throughput above allows roughly 600 MB. That assumption is deliberately modest: the cost
of being wrong is asymmetric — a refused archive is a sentence somebody reads, a truncated one is
a corrupt file found weeks later.

### The one limit classic PHP actually hits

`max_execution_time` does not count the waiting, but it **does count the compressing**. Deflating
a few gigabytes of files that are not already compressed is processor work, it accumulates, and
reaching the limit kills the script after the 200 and the first bytes have gone.

Normally that never happens: the stream calls `set_time_limit(0)` before writing a byte. The case
worth reporting is a host where `disable_functions` has taken that function away — ordinary on
shared hosting, and exactly the kind of host running mod_php rather than a pool it configured
itself. The health report then names the seconds and the two ways out.

⚠️ **The report and the stream read that answer from the same place.** A report promising the
limit will be lifted, beside a stream that silently could not, is worse than no report: it is the
sentence somebody trusts while looking for the fault somewhere else.

The refusal carries its own reason:

| `reason` | Means |
|---|---|
| `archive_too_large` | the selection exceeds `max_bytes` — a policy you chose |
| `archive_beyond_capacity` | the policy exceeds the machine — the fix is in the server's configuration, not in the selection, and the health report names which file that is here |

⚠️ **`max_bytes` of zero is not infinity here.** It means the package imposes no ceiling of its
own; read as "the machine can send anything", it is what lets a two-hour archive start.

### What the stream does for itself

Before writing a byte it turns `zlib.output_compression` off for its own response, lifts its own
time limit, and has ZipStream flush each file as it goes.

⚠️ **What it deliberately does not do is close buffers it did not open.** An output buffer around
the request belongs to whoever opened it — the framework, your middleware, a test runner — and
ending it discards their output as well as freeing ours. Buffering is reported by the health
check instead, where somebody can act on it.
