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
 */

return [

    // ── Uploads ─────────────────────────────────────────────────────────────

    'uploads.upload_max_filesize' => [
        'title' => 'PHP file size limit (upload_max_filesize)',
        'ok' => 'PHP accepts :allowed per file; the library asks for :wanted.',
        'error' => 'PHP refuses anything above :allowed, but the library is configured to accept :wanted. Files in between are rejected before this package runs, with an empty response and no reason.',
    ],

    'uploads.post_max_size' => [
        'title' => 'PHP request size limit (post_max_size)',
        'ok' => 'PHP accepts requests of :allowed; the library asks for :wanted.',
        'error' => 'PHP refuses requests above :allowed, but the library is configured to accept :wanted. This bounds the whole request — the file plus its fields — so it must be larger than the file limit, not equal to it.',
    ],

    'uploads.fix' => 'Set :directive to at least :wanted in php.ini, or lower mediahub.uploads.max_size to :kilobytes (kilobytes).',

    // ── Archives ────────────────────────────────────────────────────────────

    'archives.capacity' => [
        'title' => 'Archive size this machine can finish sending',
        'ok' => 'Archives are capped at :configured, which this machine is expected to deliver.',
        'warning' => 'The configuration allows :configured, but this machine is only expected to finish :deliverable. Anything larger is refused before it starts — which is deliberate: an archive cut off halfway has already sent its 200, so it downloads and opens with files missing.',
    ],

    'archives.capacity.declare' => 'Set mediahub.archives.time_budget to the number of seconds a download may really run here — your PHP-FPM request_terminate_timeout and your proxy timeout, whichever is smaller. Neither can be read from inside PHP, so until it is declared the package assumes sixty seconds.',
    'archives.capacity.lower' => 'Either lower mediahub.archives.max_bytes to :deliverable, or raise mediahub.archives.time_budget and the timeouts behind it.',

    'archives.buffering' => [
        'title' => 'Output buffering (zlib.output_compression)',
        'ok' => 'Off, so archives stream straight to the connection.',
        'warning' => 'On. The package turns it off for its own responses, so archives still stream — but anything else streaming from this application is being held in memory first.',
        'error' => 'On, and it cannot be changed from PHP on this machine. Every byte of an archive is held in memory before any of it is sent, so a large one exhausts the memory limit instead of downloading.',
    ],

    'archives.buffering.fix' => 'Set zlib.output_compression to Off in php.ini. Compressing a ZIP a second time costs processor time for nothing anyway.',

    // ── Images ──────────────────────────────────────────────────────────────

    'images.memory' => [
        'title' => 'Memory against the largest image accepted',
        'ok' => 'Decoding the largest accepted image needs about :needed, within the :limit available.',
        'warning' => 'The largest accepted image is :megapixels megapixels, which needs about :needed to decode — more than the :limit PHP allows. It is the pixels that exhaust memory, not the file size: a photograph of a few megabytes becomes hundreds once decoded.',
    ],

    'images.memory.fix' => 'Raise memory_limit above :needed, or lower mediahub.uploads.max_image_pixels.',

    // ── Extensions ──────────────────────────────────────────────────────────

    'extensions.zip' => [
        'title' => 'The zip extension',
        'ok' => 'Loaded.',
        'error' => 'Missing. Downloading a folder or a batch as an archive cannot work without it.',
    ],

    'extensions.fileinfo' => [
        'title' => 'The fileinfo extension',
        'ok' => 'Loaded.',
        'error' => 'Missing. The real type of an uploaded file is read from its content; without this, only the extension supplied by the client is left, and an executable renamed to .jpg passes.',
    ],

    'extensions.gd' => [
        'title' => 'The gd extension',
        'ok' => 'Loaded.',
        'warning' => 'Missing, and it is the chosen image driver. Files are still stored and served; no thumbnails are produced.',
    ],

    'extensions.imagick' => [
        'title' => 'The imagick extension',
        'ok' => 'Loaded.',
        'warning' => 'Missing, and it is the chosen image driver. Files are still stored and served; no thumbnails are produced.',
    ],

    'extensions.fix' => 'Install the :extension extension, or choose a configuration that does not need it.',

];
