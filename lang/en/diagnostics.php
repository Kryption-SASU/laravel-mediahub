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

        'buffering' => [
            'title' => 'Output buffering (zlib.output_compression)',
            'ok' => 'Off, so archives stream straight to the connection.',
            'warning' => 'On. The package turns it off for its own responses, so archives still stream — but anything else streaming from this application is being held in memory first.',
            'error' => 'On, and it cannot be changed from PHP on this machine. Every byte of an archive is held in memory before any of it is sent, so a large one exhausts the memory limit instead of downloading.',

            'fix' => 'Set zlib.output_compression to Off in php.ini. Compressing a ZIP a second time costs processor time for nothing anyway.',
        ],
    ],

    'images' => [

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
