<?php

declare(strict_types=1);

/*
 * The health report, in words.
 *
 * ⚠️ EVERY RECOMMENDATION NAMES A DIRECTIVE AND A VALUE. "post_max_size is too small" sends
 * somebody to a search engine; "set it to at least 200M, or lower mediahub.uploads.max_size to
 * 8000" is a decision they can take in a minute. A report that only diagnoses is a report people
 * stop opening.
 *
 * ⚠️ AND IT SAYS WHERE IT CANNOT SEE. PHP-FPM's `request_terminate_timeout` and the front-end
 * server's proxy timeout are what really cut a long download, and no code inside the process can
 * read them. Saying so is what keeps the rest of the report trustworthy.
 *
 * ⚠️ THE STRUCTURE IS NESTED, AND THAT IS NOT A MATTER OF TASTE. A key like
 * `'uploads.post_max_size' => ['title' => …]` is unreachable: asked for
 * `uploads.post_max_size.title`, Laravel tries the whole literal key, fails, then walks the
 * segments looking for `['uploads']['post_max_size']['title']` — and finds nothing. It returns
 * the key itself, so the screen shows `mediahub::diagnostics.uploads.post_max_size.title` to
 * somebody trying to configure a server. Shipped that way once, and caught on a real screen.
 */

return [

    'runtime' => [

        'sapi' => [
            'title' => 'How PHP runs here (:sapi)',
            'ok' => 'Requests are served through the :sapi interface. What bounds a long download here is :timeouts — none of which can be read from inside PHP, which is why the archive budget below is declared rather than detected.',
            'warning' => 'This report was produced from the command line, which is not the interface that serves your site. Every limit below is the console\'s own: a separate php.ini for it is the normal arrangement, so these numbers may have nothing to do with the ones your visitors meet.',

            'fix' => 'Open the health report from a browser instead, so it reads the runtime that actually answers requests.',
        ],

        /*
         * ⚠️ ONE PHRASE PER FAMILY, AND THE POINT OF EACH IS THE FILE IT SENDS SOMEBODY TO.
         * `request_terminate_timeout` is exact under PHP-FPM and does not exist under mod_php;
         * an Apache host told to edit `php-fpm.conf` looks for a file they do not have and
         * concludes the report is about a different product.
         */
        'timeouts' => [
            'fpm' => 'your PHP-FPM pool\'s request_terminate_timeout and your front-end server\'s proxy timeout, whichever is smaller',
            'module' => 'your front-end server or CDN, if there is one — mod_php sets no limit on how long a request may run, and Apache\'s own Timeout only fires when the connection stalls rather than when it is merely slow',
            'cgi' => 'the timeout of whatever speaks FastCGI to PHP — nginx\'s fastcgi_read_timeout, or FcgidIOTimeout under mod_fcgid, sixty seconds by default either way',
            'cli' => 'nothing at all on the command line, which is why this is the one runtime whose figures say least about your site',
            'unknown' => 'whatever supervises this interface — this package does not recognise it, and would rather say so than send you to a configuration file you do not have',
        ],
    ],

    'uploads' => [

        'upload_max_filesize' => [
            'title' => 'PHP file size limit (upload_max_filesize)',
            'ok' => 'PHP accepts :allowed per file; the library asks for :wanted.',
            'error' => 'PHP refuses anything above :allowed, but the library is configured to accept :wanted. Files in between are rejected before this package runs, with an empty response and no reason.',
        ],

        'post_max_size' => [
            'title' => 'PHP request size limit (post_max_size)',
            'ok' => 'PHP accepts requests of :allowed; the library asks for :wanted.',
            'error' => 'PHP refuses requests above :allowed, but the library is configured to accept :wanted. This bounds the whole request — the file plus its fields — so it must be larger than the file limit, not equal to it.',
        ],

        'fix' => 'Set :directive to at least :wanted in php.ini, or lower mediahub.uploads.max_size to :kilobytes (kilobytes).',
    ],

    'archives' => [

        'capacity' => [
            'title' => 'Archive size this machine can finish sending',
            'ok' => 'Archives are capped at :configured, which this machine is expected to deliver.',
            'warning' => 'The configuration allows :configured, but this machine is only expected to finish :deliverable. Anything larger is refused before it starts — which is deliberate: an archive cut off halfway has already sent its 200, so it downloads and opens with files missing.',

            /*
             * ⚠️ A COLON RATHER THAN "IS", AND IT IS NOT A MATTER OF STYLE. The sentence takes
             * five different completions depending on the runtime, one of them "nothing at all
             * on the command line" — which does not follow "what bounds it is". A colon accepts
             * all five.
             */
            'declare' => 'Set mediahub.archives.time_budget to the number of seconds a download may really run here. What bounds it on this machine: :timeouts. None of that can be read from inside PHP, so until it is declared the package assumes sixty seconds.',
            'lower' => 'Either lower mediahub.archives.max_bytes to :deliverable, or raise mediahub.archives.time_budget and the timeouts behind it — here, :timeouts.',
        ],

        'execution_time' => [
            'title' => 'Time an archive may spend compressing (max_execution_time)',
            'ok' => 'An archive is not cut short by PHP itself here: :because.',
            'warning' => 'PHP stops a script after :limit seconds, and set_time_limit is disabled on this machine, so the package cannot lift it for the response it streams. Waiting for storage does not count against that limit, but compressing does — a large archive of files that are not already compressed can reach it, and it is killed after the download has started, leaving a ZIP that opens with files missing.',

            'because' => [
                'absent' => 'PHP sets no execution time limit',
                'lifted' => 'the package lifts the :limit-second limit for the response it streams',
            ],

            'fix' => 'Raise max_execution_time in php.ini, or take set_time_limit out of disable_functions so the package can lift it where it needs to.',
        ],

        'progress' => [
            'title' => 'Watching a download in progress (cache store: :store)',
            'ok' => 'The :store store can be read by a second request, so the library can show how far an archive has got. ⚠️ Behind a load balancer, check that it is shared between your servers as well — apc and octane are per-machine, and file is too unless the directory itself is shared.',
            'warning' => 'The :store store lives and dies inside one request, so a second one asking how far an archive has got is always told "never heard of it". Archives still download; no progress is ever shown, and nothing on the screen says why.',

            'fix' => 'Point mediahub.archives.progress_store at a store two requests can share — redis, memcached, database or file — or change the application default. Nothing else depends on this: leaving it as it is costs the progress figure and nothing more.',
        ],

        'buffering' => [
            'title' => 'Output buffering (zlib.output_compression)',
            'ok' => 'Off, so archives stream straight to the connection.',
            'warning' => 'On. The package turns it off for its own responses, so archives still stream — but anything else streaming from this application is being held in memory first.',
            'error' => 'On, and it cannot be changed from PHP on this machine. Every byte of an archive is held in memory before any of it is sent, so a large one exhausts the memory limit instead of downloading.',

            'fix' => 'Set zlib.output_compression to Off in php.ini. Compressing a ZIP a second time costs processor time for nothing anyway.',
        ],
    ],

    /*
     * ⚠️ THE RESOLVED PATH IS ON THE SCREEN, NOT MERELY "FOUND". A host with three ffmpegs and a
     * configured path has exactly one question — which one is being run — and it is the one a
     * yes/no cannot answer.
     */
    'tools' => [

        'programs' => [
            'title' => 'Running a program from a request',
            'ok' => 'Allowed.',
            'warning' => 'Forbidden on this installation: proc_open is not available, so no tool above could be asked its version and none can be run while answering a request. Thumbnails built by a queue worker are unaffected — the command line usually runs under a different configuration.',
            'fix' => 'Nothing needs changing if a queue worker builds the derivatives; check one runs. Allowing proc_open for the web server would also work, and grants every request the right to start programs — a far wider permission than a thumbnail asks for.',
        ],

        'ffmpeg' => [
            'title' => 'ffmpeg — thumbnails of videos',
            'ok' => 'Found at :path (:version).',
            'warning' => 'Not available, so videos keep their type icon instead of a picture. Nothing else is affected: files upload, download and play as before.',
        ],

        'ffprobe' => [
            'title' => 'ffprobe — the length of a video',
            'ok' => 'Found at :path (:version).',
            'warning' => 'Not available. The frame is still captured, but the second it is asked for cannot be checked against the length of the file — and a capture past the end of a video silently produces nothing at all.',
        ],

        'pdf' => [
            'title' => 'The first page of a PDF (:tool)',
            'ok' => 'Rendered by :tool, found at :path (:version).',
            'warning' => 'Neither pdftoppm nor gs is available, so documents keep their type icon instead of a picture of their first page.',

            'fix' => 'Install poppler-utils, which provides pdftoppm — or point mediahub.tools.pdf at a renderer you already have. ⚠️ Ghostscript works and is accepted, but poppler is preferred where both are present: gs is a complete PostScript interpreter, which is what earned ImageMagick its worst vulnerabilities, while pdftoppm only ever draws pages.',
        ],

        'fix_missing' => 'Install :tool, or point mediahub.tools.:tool at it if it lives somewhere the PATH does not reach — a queue worker rarely has the same PATH as a shell.',
        'fix_configured' => 'mediahub.tools.:tool names a path that is not an executable file here. ⚠️ Nothing was used in its place: falling back to whatever is on the PATH would run a different program than the one you named, and say nothing about it.',
    ],

    'images' => [

        /*
         * ⚠️ WHAT IT CAN DO, NEVER WHAT IT CLAIMS. `queryFormats()` is an advertisement: it
         * answers "yes" for MP4, MOV and PDF on machines where all three fail. This package has
         * been caught by it twice.
         */
        'imagick' => [
            'title' => 'What ImageMagick can actually read here',
            'ok' => 'Proven by trying, one format at a time: :proven. ⚠️ This is what it can do, not what it advertises — queryFormats() answers "yes" for MP4, MOV and PDF on machines where every one of them fails, because the video formats need a delegate and distributions cut them all in policy.xml.',
        ],

        'memory' => [
            'title' => 'Memory against the largest image accepted',
            'ok' => 'Decoding the largest accepted image needs about :needed, within the :limit available.',
            'warning' => 'The largest accepted image is :megapixels megapixels, which needs about :needed to decode — more than the :limit PHP allows. It is the pixels that exhaust memory, not the file size: a photograph of a few megabytes becomes hundreds once decoded.',

            'fix' => 'Raise memory_limit above :needed, or lower mediahub.uploads.max_image_pixels.',
        ],
    ],

    'extensions' => [

        'zip' => [
            'title' => 'The zip extension',
            'ok' => 'Loaded.',
            'error' => 'Missing. Downloading a folder or a batch as an archive cannot work without it.',
        ],

        'fileinfo' => [
            'title' => 'The fileinfo extension',
            'ok' => 'Loaded.',
            'error' => 'Missing. The real type of an uploaded file is read from its content; without this, only the extension supplied by the client is left, and an executable renamed to .jpg passes.',
        ],

        'gd' => [
            'title' => 'The gd extension',
            'ok' => 'Loaded.',
            'warning' => 'Missing, and it is the chosen image driver. Files are still stored and served; no thumbnails are produced.',
        ],

        'imagick' => [
            'title' => 'The imagick extension',
            'ok' => 'Loaded.',
            'warning' => 'Missing, and it is the chosen image driver. Files are still stored and served; no thumbnails are produced.',
        ],

        'fix' => 'Install the :extension extension, or choose a configuration that does not need it.',
    ],

];
