<?php

declare(strict_types=1);

/*
 * THE PACKAGE'S CONFIGURATION — and the only place where it learns anything about its host.
 *
 * ⚠️ EVERY VALUE HARDCODED IN THE CODE IS A DESIGN BUG. The module this package replaces named
 * a disk, an organisation and routes in its code: that is what made forking it inevitable, and
 * then upgrading it impossible.
 */

return [

    /*
     * ── 1. WHERE THE MEDIA COME FROM ────────────────────────────────────────
     *
     * `standalone`: the package publishes its own tables and manages them.
     * `table`     : it plugs onto EXISTING tables through an adapter.
     * `custom`    : the host supplies its own implementation; the package never touches the
     *               database again.
     */
    'backend' => [
        'driver' => env('MEDIAHUB_BACKEND', 'standalone'),

        'table_prefix' => 'mediahub_',

        /*
         * ⚠️ `int` BY DEFAULT, AND THAT IS NOT A COMFORT CHOICE. The legacy schemas this
         * package must be able to adopt have unsigned integer keys: requiring UUIDs would rule
         * out `table` mode, which is its whole reason to exist.
         */
        'key_type' => 'int',

        /*
         * ── ADOPTING AN EXISTING SCHEMA ─────────────────────────────────────
         *
         * ⚠️ A PRESET RATHER THAN A COPIED MAP. `legacy` describes a widely deployed
         * schema as it exists IN THE DATABASE — not as its migrations describe it, which were
         * wrong on at least three counts. Without it, adopting that schema would mean copying
         * forty lines of column mapping into every installation, and the fortieth would be
         * wrong.
         *
         * ⚠️ AND EVERYTHING CAN BE OVERRIDDEN ON TOP, column by column: a schema that has
         * drifted by a hair must stay pluggable without forking the preset.
         */
        'preset' => env('MEDIAHUB_PRESET'),

        /*
         * Table names. Absent = prefixed (`mediahub_files`); an explicit `null` = the table
         * DOES NOT EXIST in this schema, and whatever depends on it must know.
         */
        'tables' => [],

        /*
         * ⚠️ THE EXPOSED KEY. `uuid` by default, because a sequential identifier in a URL is an
         * invitation to enumerate. An adopted schema without a `uuid` column replaces it with
         * `id` — and the defence then rests ENTIRELY on the scoping, which has to be a
         * conscious choice.
         */
        'route_key' => null,

        /*
         * ⚠️ WHAT "NO PARENT" IS WORTH IN THIS SCHEMA. `null` here, `0` on legacy schemas that
         * declare `folder_id NOT NULL DEFAULT 0`. Getting it wrong does not raise: a
         * `whereNull` on a column holding zero returns an EMPTY library, without a word.
         */
        'root_folder' => null,

        /* How visibility is stored. Empty: as is. Legacy: `['private' => 0]`. */
        'visibility' => [],

        'adapter' => null,

        /**
         * The column-by-column mapping: `logical => physical`, or `logical => null` when the
         * column does not exist.
         *
         * @see config/presets/legacy.php for a complete, commented example
         */
        'map' => [],
    ],

    /*
     * ── 2. WHERE THE BYTES GO ───────────────────────────────────────────────
     *
     * Two ways of saying it, and a single mechanism behind them.
     *
     *   `disk`: a disk ALREADY configured by the host — any of them, including the ones it
     *           adds later. The package names none in its code.
     *   `path`: a plain path on the machine. The package then DECLARES a local disk on it, and
     *           everything else keeps going through disks.
     *
     * ⚠️ `path` MODE IS NOT A SECOND IMPLEMENTATION, AND THAT IS DELIBERATE. Handling a path
     * separately would mean writing every read, every write, every existence check, every
     * stream and every URL twice — and the second, less travelled, would be the one that rots.
     * The module this package replaces juggled `Storage::disk()` and `public_path()`: the two
     * diverged when it moved to the cloud, and its download has been broken ever since without
     * anyone noticing.
     */
    'storage' => [
        'driver' => env('MEDIAHUB_STORAGE_DRIVER', 'disk'),

        /** Used when `driver` is `disk`. The disk must exist in `filesystems`. */
        'disk' => env('MEDIAHUB_DISK', 'public'),

        /*
         * Used when `driver` is `path`. An **ABSOLUTE path**, outside the web root.
         *
         * ⚠️ OUTSIDE `public/`, AND THAT IS NOT NEGOTIABLE — the package REFUSES to start
         * otherwise. Inside the web root, the front-end server serves the files without going
         * through PHP: the scope, the access policy and the signed URLs become purely
         * decorative, and a guessed identifier is enough to read everything.
         *
         * ⚠️ AND ABSOLUTE, NOT RELATIVE: the web server, a scheduler command and a queue worker
         * do not share a current directory. A relative path would scatter the media across
         * three places, two of them invisible.
         *
         * The reasonable choice on a Laravel installation: `storage_path('app/media')`. It is
         * outside the web root, it is already excluded from the repository, and it is already
         * within the backup perimeter.
         *
         * ⚠️ AND IT IS SHARED WITH NOTHING ELSE. The package writes there AND deletes there:
         * pointing it at a folder another application uses amounts to handing it the right to
         * erase that application's files.
         */
        'path' => env('MEDIAHUB_PATH'),

        /** The name of the disk built in `path` mode — visible in the `disk` column. */
        'name' => 'mediahub',

        /*
         * A public URL for this path, if the host has exposed it itself — a symlink, a
         * subdomain, a cache in front. Without it the package serves through its own route,
         * which is the normal case since the folder is private.
         */
        'url' => env('MEDIAHUB_PATH_URL'),
    ],

    'root' => '',

    /*
     * ⚠️ THE URL IS NEVER STORED, it is computed. The database keeps only a disk and a relative
     * path: changing storage or moving behind a cache then requires no data migration.
     *
     * ⚠️ AND THE FALLBACK MUST BE LOUD. A signature that fails and silently falls back to the
     * public URL serves permanent links with no message saying so.
     */
    'urls' => [
        'generator' => null,

        'signed' => env('MEDIAHUB_SIGNED_URLS', true),

        /*
         * In minutes.
         *
         * ⚠️ THERE IS NO "SIGNED BUT ETERNAL". A zero or negative value is brought back to one
         * minute: a signed link that never expires combines the drawbacks of both worlds — it
         * gives the impression of expiring, and it does not. For a permanent link, `signed` is
         * set to false, and that can be read.
         */
        'ttl' => 60,

        /*
         * ⚠️ THERE IS NO LONGER A `renewal_parameter`, AND THAT IS A CORRECTION. The idea was
         * that the client would read the identifier back out of the URL to ask for a fresh one.
         * Two measurements ruled it out: Laravel's implicit binding matches the route parameter
         * to the NAME of the controller argument, which a configuration file cannot rename; and
         * on the path that matters — the storage's pre-signed URL — adding the slightest
         * parameter invalidates the signature, since it covers the entire query string. The
         * identifier therefore travels in the JSON resource, where it is always present, and
         * the URL is never touched.
         */
    ],

    /*
     * ── 3. WHO IS LOOKING, AND WHAT THEY MAY SEE ────────────────────────────
     *
     * ⚠️ THE SCOPE COMES FROM THE CALLER, NEVER FROM SESSION STATE. A door without a session —
     * a mobile API, an embedded widget — would otherwise record media with no owner, silently
     * and permanently.
     */
    'context' => [
        'identity' => null,
        'guard' => env('MEDIAHUB_GUARD', 'web'),
        'scope' => null,
        'policy' => null,
        'quota' => null,

        /*
         * ⚠️ WHO OWNS WHAT IS CREATED. Left null, it is the user signed in on the configured
         * guard — the right answer almost everywhere, and the reason `composer require` is
         * enough. A host keying ownership on something else — a team, a tenant, an impersonated
         * account — names its own `MediaOwner` here.
         *
         * ⚠️ AND IT IS NOT DECORATIVE ON AN ADOPTED SCHEMA. Where `user_id` is `NOT NULL`, an
         * owner nobody supplies is not a missing fact but a refused insert: uploading a file and
         * creating a folder both fail on a constraint.
         */
        'owner' => null,
    ],

    /*
     * ── 4. THE DOMAIN ───────────────────────────────────────────────────────
     */
    'features' => [
        'folders' => true,
        'trash' => true,
        /*
         * ⚠️ SHARING IS NOT WRITTEN YET, AND THAT IS NOT AN OVERSIGHT. On the estate used to
         * scope this package, the feature had existed for eight years — table, controller, four
         * actions — and counted ZERO shares, ZERO public media, ZERO public folders. Writing a
         * schema no code fills is condemning yourself to evolve it blind.
         */
        'sharing' => false,
        'favorites' => true,
        'quota' => false,
        'focus_point' => false,
        'external_providers' => false,
    ],

    'uploads' => [
        /**
         * In kilobytes.
         *
         * ⚠️ A LIBRARY THAT ACCEPTS VIDEO CANNOT STOP AT EIGHT MEGABYTES. A minute filmed on a
         * phone goes well past that; the previous ceiling refused, in practice, everything this
         * package has just opened up.
         *
         * ⚠️ AND THIS IS NOT THE REAL CEILING. `upload_max_filesize`, `post_max_size` and the
         * front-end server's limit apply BEFORE this one, and are lower by default. Raising
         * this value alone is never enough — it is a bound the package imposes on itself, not a
         * permission it grants.
         */
        'max_size' => 204800,

        /*
         * ⚠️ AN ALLOW-LIST, AND SVG IS NOT ON IT. An SVG is an EXECUTABLE document, not an
         * image: allowing it first requires deciding what to do with it — sanitise it, or serve
         * it as an attachment rather than inline.
         *
         * ⚠️ AND THE EXTENSION IS NEVER ENOUGH ON ITS OWN. It is supplied by the client: the
         * real type is read from the CONTENT, and their agreement is checked. A `.jpg` that is
         * an SVG must be refused.
         */
        'allowed_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp',

            /*
             * ⚠️ HEIC IS THE DEFAULT FORMAT OF EVERY IPHONE PHOTO. Refusing it amounts to
             * refusing half the uploads of a mobile estate — and an upload refusal is never
             * explainable to the person on the receiving end.
             */
            'heic', 'heif',

            /*
             * ⚠️ VIDEOS COME FROM THREE WORLDS THAT DO NOT TALK TO EACH OTHER. An iPhone
             * produces QuickTime, an Android 3GPP or WebM, a camcorder AVCHD, and Windows ASF.
             * Accepting only MP4 amounts to refusing half of what people actually have in their
             * hands — and the refusal, again, cannot be explained.
             */
            'mp4', 'm4v', 'mov',
            '3gp', '3g2', 'webm', 'mkv',
            'avi', 'wmv', 'asf',
            'flv', 'ogv', 'mpg', 'mpeg',
            'ts', 'm2ts', 'mts',

            'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'wma',

            'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
        ],

        /*
         * ⚠️ THE GUARD AGAINST DECOMPRESSION BOMBS: an image of a few kilobytes can claim
         * several gigabytes once decoded. The ceiling is checked BEFORE decoding, otherwise it
         * arrives too late.
         */
        'max_image_pixels' => 50_000_000,

        'reserved_names' => [],
    ],

    /*
     * ── IMAGES ──────────────────────────────────────────────────────────────
     *
     * `gd`     : almost always present, enough for the common formats;
     * `imagick`: reads more — TIFF, HEIC, PDF — but requires the extension;
     * `none`   : no derivatives, and that is a NORMAL state.
     *
     * ⚠️ NEITHER LIBRARY IS REQUIRED BY THE PACKAGE. A host that only stores documents does not
     * need one, and a minimal runtime image does not have one. The chosen driver simply answers
     * "I cannot" when its extension is missing, and the original keeps being served — an
     * impossible derivative has never stopped a file from existing.
     *
     * ⚠️ AND THE CHOICE IS NOT GUESSED: the package does not switch to another driver on its
     * own. A silent fallback would produce different thumbnails depending on the machine, and
     * nobody would know why.
     */
    'images' => [
        /*
         * ⚠️ AND THE CHOSEN DRIVER PROMISES ONLY WHAT THE MACHINE CAN DO. GD and ImageMagick
         * are built à la carte: GD without libwebp ignores WebP, ImageMagick without its
         * delegate ignores HEIC, and PDF additionally demands a Ghostscript that a security
         * policy often forbids. None of that is settled here — it is observed at runtime, on
         * the host, format by format.
         *
         * There is therefore no list of formats to keep up to date in this file: the only
         * question asked here is "which driver", never "which formats".
         */
        'driver' => env('MEDIAHUB_IMAGE_DRIVER', 'gd'),

        /*
         * ── THE DECODER'S BOUNDS ────────────────────────────────────────────
         *
         * ⚠️ THEY ONLY CONCERN IMAGEMAGICK, which opens formats whose cost cannot be known in
         * advance. GD is already bounded by `uploads.max_image_pixels`, which is checked
         * against the header before any decoding.
         *
         * ⚠️ AND ONLY `max_side` ACTUALLY REFUSES. ImageMagick's memory limits merely decide
         * WHERE the pixels are cached: once exceeded, it spills to disk and carries on.
         * Measured on the bench — a 4000×4000 image goes through under a one-kilobyte memory
         * limit. It is the dimension bound that stops a decompression bomb, before any
         * allocation.
         *
         * ⚠️ THESE BOUNDS ARE PROCESS-WIDE. ImageMagick does not attach them to an instance:
         * setting them changes the behaviour of everything using it in the application. That is
         * accepted — a protection that only held for our own calls would not be one.
         */
        'limits' => [
            /** In pixels, for width AND height. The only one that refuses. */
            'max_side' => 20000,

            'memory_mb' => 256,
            'map_mb' => 512,
            'disk_mb' => 1024,

            /** In seconds; zero means "no limit" in ImageMagick. */
            'seconds' => 30,

            /** ⚠️ ONE THREAD: a job queue would start as many as there are cores. */
            'threads' => 1,
        ],
    ],

    /*
     * ⚠️ DERIVATIVES ARE FILED NEXT TO THEIR ORIGINAL, and their name is derived from it. Any
     * reader that DEDUCES that path by string manipulation would break if they lived elsewhere.
     */
    /*
     * ── THE PROGRAMS THIS PACKAGE ASKS THE MACHINE FOR ──────────────────────
     *
     * ⚠️ NO PHP EXTENSION HERE CAN DRAW A VIDEO OR A PDF, AND THE ONE THAT CLAIMS TO IS LYING.
     * `Imagick::queryFormats()` announces MP4, MOV and PDF; the video formats go through a
     * DELEGATE — ffmpeg itself — and distributions cut every delegate in `policy.xml`, while the
     * PDF coder is cut outright. Measured on this machine and on the production server.
     *
     * ⚠️ NULL MEANS "GO AND LOOK", a path means "use exactly this". A path that is not an
     * executable file is NOT quietly replaced by whatever is on the PATH: the health report says
     * the configured one is unusable, because a host who wrote a path and got somebody else's
     * binary has been told nothing at all.
     *
     * ⚠️ AND NOTHING IS EVER RUN THROUGH A SHELL. Arguments are handed to the kernel one by one,
     * so there is no command line for a file name to be quoted into.
     */
    'tools' => [
        /** Video frames. Installing ffmpeg installs ffprobe with it. */
        'ffmpeg' => env('MEDIAHUB_FFMPEG'),

        /** ⚠️ USED FOR THE DURATION: a frame asked for at 3s in a 2s video is silently nothing. */
        'ffprobe' => env('MEDIAHUB_FFPROBE'),

        /*
         * The first page of a PDF — `pdftoppm` (poppler) or `gs` (ghostscript).
         *
         * ⚠️ POPPLER IS PREFERRED WHEN BOTH ARE PRESENT. Ghostscript is a complete PostScript
         * interpreter, which is precisely what earned ImageMagick its worst vulnerabilities;
         * `pdftoppm` only ever draws pages. Ghostscript is still accepted — refusing it would
         * help nobody, since a host that already has it gets its thumbnails either way.
         */
        'pdf' => env('MEDIAHUB_PDF'),
    ],

    'conversions' => [
        /*
         * ⚠️ THE QUEUE, BECAUSE DERIVATIVES ARE BUILT OUTSIDE THE REQUEST. `null` takes the
         * default queue; naming one isolates slow, bulky work from the rest, so that it delays
         * neither emails nor notifications.
         */
        'queue' => env('MEDIAHUB_CONVERSIONS_QUEUE'),

        /*
         * ⚠️ NOTHING HERE MENTIONS FILE TYPES, AND THAT IS DELIBERATE. These definitions apply
         * only to what the driver can convert: a video, an audio file, an archive produce NONE
         * — not even a failed row, which would send someone looking for a failure where there
         * was nothing to do.
         *
         * ⚠️ AND THE ORIGINAL IS NEVER TOUCHED, whatever its type. Derivatives are EXTRA files:
         * a library that "optimises" what it is entrusted with destroys without saying so, and
         * that is irreversible.
         */
        'definitions' => [
            'thumb' => ['width' => 256, 'height' => 256, 'fit' => 'cover'],
        ],
    ],

    /*
     * ── 5. WHAT IS EXPOSED ──────────────────────────────────────────────────
     */
    'routes' => [
        'enabled' => true,
        'prefix' => 'media',
        'middleware' => ['web', 'auth'],
        'domain' => null,
        'as' => 'mediahub.',
        'parameters' => [],
    ],

    /*
     * ── ARCHIVES ────────────────────────────────────────────────────────────
     *
     * ⚠️ THE ARCHIVE IS STREAMED, AND NO TEMPORARY FILE IS WRITTEN. The module this package
     * replaces built its ZIP inside the web root, under a guessable name, and only deleted it
     * after a successful send: an interrupted request left it served by the front-end server to
     * anyone who guessed its name.
     *
     * ⚠️ BUT STREAMING DOES NOT PROTECT AGAINST EVERYTHING, HENCE THESE BOUNDS. They defend
     * neither memory nor disk — streaming takes care of that: they defend against TIME. An
     * archive that exceeds `max_execution_time` is cut in the middle, and the result is a
     * TRUNCATED archive that downloads and opens normally, with files missing. Refusing before
     * the first byte is the only window where a refusal can still be an HTTP status code.
     */
    'archives' => [
        /** Zero means "no limit" — and accepts silent truncation. */
        'max_files' => 1000,

        /** In bytes. Two gigabytes by default. */
        'max_bytes' => 2147483648,

        'file_name' => 'medias.zip',

        /*
         * ⚠️ THE REPORT OF THE FILES THAT COULD NOT BE FOUND, WRITTEN INSIDE THE ARCHIVE. Once
         * building has started the status code is already gone: this is the only place left to
         * say what is missing. Without it, an incomplete archive is indistinguishable from a
         * complete one — which is exactly what the original module produced.
         */
        'report_name' => 'MISSING.txt',

        /*
         * ── WHAT THIS MACHINE CAN ACTUALLY FINISH SENDING ───────────────────
         *
         * ⚠️ THE TWO LIMITS ABOVE ARE A POLICY; THESE TWO ARE A CAPACITY. An archive that dies
         * halfway has already sent its 200: the browser saves a ZIP that opens, lists most of
         * its files and is missing the rest, and nothing anywhere says so. The package
         * therefore refuses before the first byte rather than doing its best.
         *
         * ⚠️ AND WHAT CUTS THE DOWNLOAD CANNOT BE READ FROM INSIDE PHP. `max_execution_time` is
         * largely beside the point — on Unix it does not count time spent waiting on input and
         * output, which is nearly all of streaming a remote object store. The real ceilings are
         * PHP-FPM's `request_terminate_timeout` and the front-end server's proxy timeout.
         *
         * ⚠️ SO SAY WHAT YOURS IS. Left at zero, the package assumes sixty seconds — the
         * commonest default of both — which with the throughput below allows roughly 600 MB.
         * The health report says when that assumption is what is holding the ceiling down.
         */

        /** Seconds a streamed response may really run here. 0 = undeclared, and assumed modest. */
        'time_budget' => 0,

        /*
         * ── WHERE THE PROGRESS OF A DOWNLOAD IS LEFT ────────────────────────
         *
         * ⚠️ THE BROWSER NEVER REPORTS A DOWNLOAD TO THE PAGE THAT ASKED FOR IT. No event, no
         * API: once the attachment is taken, the page is blind. So the only progress anybody can
         * show is the server's own — and the request that knows it is the one still streaming,
         * which will not be free to answer until nobody needs telling. It leaves the number in
         * the cache, and a second request picks it up.
         *
         * ⚠️ WHICH ONLY WORKS IF TWO REQUESTS CAN MEET THERE. `array` and `null` live and die
         * inside one request: the answer would always be "never heard of it", and no bar would
         * appear. Nothing breaks — the page falls back to knowing that the answer has begun —
         * but the health report says so out loud rather than leaving you to wonder.
         *
         * Null means the application's own default store.
         */
        'progress_store' => null,

        /**
         * Bytes per second the storage is read at, used to turn the budget into a size.
         *
         * ⚠️ A STATED FIGURE RATHER THAN A MEASUREMENT. How fast a hundred objects come back
         * from a remote store is not knowable before doing it, and measuring it once would bake
         * in whatever the network was doing that afternoon.
         */
        'throughput' => 10485760,
    ],

    /*
     * ── THE HEALTH REPORT ───────────────────────────────────────────────────
     *
     * ⚠️ OFF BY DEFAULT, AND NOT BECAUSE IT IS DANGEROUS. It reports this machine's PHP limits
     * and which extensions are loaded — modest information, but information about the server
     * rather than about the library, and there is no reason for it to be one click away from
     * everybody who can look at a photograph.
     *
     * ⚠️ IT IS MEANT TO BE TURNED ON WHILE THE PACKAGE IS BEING SET UP, read, acted on, and
     * turned off again. That is also why it is a flag here rather than a permission: the person
     * who needs it is the person editing this file.
     */
    'diagnostics' => [
        'enabled' => env('MEDIAHUB_DIAGNOSTICS', false),
    ],

    'api' => [
        'enabled' => false,
        'prefix' => 'api/media',
        'middleware' => ['api'],
        'domain' => null,

        /*
         * ⚠️ A NAME PREFIX DIFFERENT FROM THE WEB ROUTES', AND IT IS NOT DECORATIVE. The same
         * route file is loaded into both groups; two registrations of the same name silently
         * override each other in the name table, and URL generation starts pointing at the
         * wrong door — the one without the same middleware.
         */
        'as' => 'mediahub.api.',
    ],

    /*
     * ── 6. THE INTERFACE ────────────────────────────────────────────────────
     *
     * `standalone`: a self-contained JavaScript package, Vue embedded — the host installs
     *               nothing.
     * `bundled`   : the host has Vue 3 and Vite, it imports the sources.
     * `none`      : the API alone.
     *
     * ⚠️ THE PACKAGE'S LOOK IS A FLOOR, NOT A DECISION. Whatever the package ships is what
     * applies when the host says nothing; the moment a `theme` is declared, the theme WINS,
     * token by token. The reverse — package values overriding a declared theme — would make
     * theming pointless, and would be discovered only on the screen.
     *
     * ⚠️ AND THESE THREE KEYS ARE READ BY NOBODY TODAY. `layout`, `isolate` and `theme` are
     * declared here so the contract is fixed before the browser side exists; nothing
     * implements them yet. They are an intention on record, not a behaviour — do not write
     * host configuration against them expecting an effect.
     */
    'ui' => [
        'enabled' => true,
        'assets' => env('MEDIAHUB_UI', 'standalone'),
        'layout' => null,
        'isolate' => false,
        'theme' => null,
    ],

    /*
     * ── 7. LANGUAGE ─────────────────────────────────────────────────────────
     */
    'locale' => null,

    /*
     * -- FETCHING A FILE FROM A URL ------------------------------------------
     *
     * ⚠️ OFF BY DEFAULT, AND THAT IS A POSITION RATHER THAN CAUTION. Fetching an address
     * somebody else chose is a request-forgery primitive: the server sits inside the network and
     * can reach the database, the queue, an admin panel bound to localhost, and -- on every major
     * cloud -- a metadata endpoint that hands credentials to anything that asks. An installation
     * that never uses this feature should not carry its risk.
     *
     * ⚠️ AND THE GUARD IS NOT OPTIONAL WHEN IT IS ON. Turning this on accepts that the package
     * will follow a URL; it does not accept that it will follow one anywhere.
     */
    'remote' => [
        'enabled' => env('MEDIAHUB_REMOTE_FETCH', false),

        /* ⚠️ `file://` READS THE DISK AND `gopher://` CAN TALK TO A REDIS. Only these two. */
        'schemes' => ['http', 'https'],

        /*
         * ⚠️ A PORT LIST IS A CHEAP AND EFFECTIVE HALF OF THIS DEFENCE. Most of what is worth
         * reaching inside a network listens somewhere other than 80 or 443.
         */
        'ports' => [80, 443],

        /*
         * ⚠️ NAMED HOSTS MAKE EVERY OTHER RULE A SECOND LINE. Where an application knows the
         * handful of places it fetches from, listing them is stronger than any address check --
         * and the match is exact, because `example.com.attacker.test` ends with `example.com`.
         */
        'hosts' => [],

        /* ⚠️ COUNTED WHILE IT ARRIVES: a Content-Length is a claim by the other side. */
        'max_bytes' => 32 * 1024 * 1024,

        'timeout' => 10,

        /*
         * ⚠️ EVERY HOP IS CHECKED AGAIN, INCLUDING THE LAST. A public URL answering 302
         * towards an internal address is the shape of this attack.
         */
        'max_redirects' => 3,
    ],

];
