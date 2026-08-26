<?php

declare(strict_types=1);

/*
 * Refusals a person can see, and what to tell them.
 *
 * ⚠️ THE PACKAGE THROWS KEYS, NOT SENTENCES, AND THAT DOES NOT CHANGE HERE. Every refusal
 * carries a stable `reason` key; these lines are a DEFAULT rendering of it. A host that wants
 * its own wording publishes this file and edits it, and a host that wants its own language
 * translates the key itself and ignores these lines entirely. Neither has to fork anything.
 *
 * ⚠️ DEVELOPER-FACING FAILURES ARE DELIBERATELY ABSENT. A missing column, an unknown storage
 * driver, an unresolvable route: nobody but the person installing the package will ever read
 * those, and translating them would only make the search engine results harder to find. They
 * stay technical, and in English.
 */

return [

    // ── Uploads ─────────────────────────────────────────────────────────────

    'too_large' => 'This file is too large.',
    'extension_not_allowed' => 'This kind of file is not accepted.',

    /*
     * ⚠️ SAY WHAT WAS SEEN, NOT WHAT WAS SUSPECTED. "The content does not match the extension"
     * is actionable — rename it, or send the real file. "Invalid file" is not, and it is the
     * message that generates support requests.
     */
    'extension_mismatch' => 'The contents of this file do not match its extension.',
    'svg_not_allowed' => 'SVG files are not accepted.',

    'unreadable' => 'This file could not be read.',
    'source_unreadable' => 'The original file could not be read.',
    'mime_unreadable' => 'The type of this file could not be determined.',
    'not_inspectable' => 'This file could not be inspected before being stored.',

    'image_unreadable' => 'This image could not be read.',
    'image_too_large' => 'This image is too large to be processed.',

    'quota_exceeded' => 'There is not enough space left.',

    // ── Library operations ──────────────────────────────────────────────────

    'item_not_found' => 'This item no longer exists.',
    'selection_empty' => 'Nothing was selected.',

    'media_name_required' => 'A name is required.',
    'folder_name_required' => 'A folder name is required.',

    // ── Attaching to a host model ───────────────────────────────────────────

    /*
     * ⚠️ "HERE", NOT "IN THIS COLLECTION". A collection is a word from the code; the person
     * reading this chose a file on a screen that says "cover" or "attachments", and telling
     * them about collections explains nothing they can act on.
     */
    'collection_type_rejected' => 'This kind of file is not accepted here.',
    'collection_file_too_large' => 'This file is too large for this place.',

    /*
     * ⚠️ THE CONSEQUENCE, NOT THE RULE. "A folder cannot contain itself" states a constraint;
     * saying the branch would disappear says why anyone should care.
     */
    'folder_cycle' => 'A folder cannot be moved inside itself.',
    'folder_too_deep' => 'This folder tree is too deep.',

    // ── Archives ────────────────────────────────────────────────────────────

    'archive_empty' => 'There is nothing to download.',
    'archive_too_many_files' => 'Too many files were selected for a single download.',
    'archive_too_large' => 'This selection is too large to download in one go.',


    // -- Building the thumbnails again --------------------------------------
    'conversion_unsupported_here' => 'No picture can be drawn for this kind of file on this server.',


    // -- Fetching from a web address ----------------------------------------
    'remote_disabled' => 'Fetching files from a web address is turned off.',
    'remote_url_invalid' => 'That web address cannot be read.',
    'remote_scheme_not_allowed' => 'Only web addresses starting with http or https are accepted.',
    'remote_credentials_not_allowed' => 'That web address carries a user name and a password.',
    'remote_port_not_allowed' => 'That web address uses a port that is not accepted.',
    'remote_host_not_allowed' => 'That website is not on the list of accepted ones.',
    'remote_address_not_allowed' => 'That web address points somewhere it may not.',
    'remote_unresolvable' => 'That website could not be found.',
    'remote_unreachable' => 'That web address could not be read.',
    'remote_too_many_redirects' => 'That web address redirects too many times.',
    'remote_too_large' => 'The file at that address is too large.',
    'remote_empty' => 'That address answered with an empty file.',
    'remote_unnamed' => 'That address does not say what the file is called. Give it a name.',
    'remote_unsupported' => 'This installation cannot fetch files from a web address.',
];
