<?php

declare(strict_types=1);

/*
 * A widely deployed legacy media schema, described as it exists IN THE DATABASE — not as its
 * migrations describe it.
 *
 * ⚠️ THIS MAP WAS WRITTEN FROM `information_schema`, AND THAT WAS NECESSARY. On the schema it
 * was measured against, the migrations were wrong on three counts: a `disk` column present on
 * both tables and declared in none of them; a parent key declared unsigned in code and found
 * SIGNED in the database, while the sibling column on the other table is unsigned; and no
 * foreign key at all between a file and its folder, where reading the migrations suggests one.
 *
 * ⚠️ THREE TRAPS, AND NONE OF THEM RAISES AN ERROR — they lie instead:
 *
 *   1. `folder_id` and `parent_id` are `NOT NULL DEFAULT 0`: the root is written as `0`, not
 *      `null`. A `whereNull()` returns zero rows against it — an empty library on a full
 *      database, with nothing to explain it;
 *   2. visibility is a `tinyint`, not a string;
 *   3. eight columns of the canonical schema simply do not exist — `uuid`, `checksum`, `type`,
 *      `extension`, `file_name`, `width`, `height`, `duration`. Three of them can be derived on
 *      read; the rest cannot be kept at all, and whatever depends on them has to know it rather
 *      than return quietly wrong lists.
 *
 * ⚠️ AND THE PATH COLUMN IS CALLED `url` WHILE HOLDING A RELATIVE PATH. Verified against real
 * rows. That is what makes adoption possible — this package only ever accepts relative paths.
 * The column name is a leftover from a time when it did hold an address, and that confusion is
 * exactly what broke downloads when the storage moved behind an object store.
 */

return [
    'tables' => [
        'files' => 'media_files',
        'folders' => 'media_folders',

        /*
         * ⚠️ THE DERIVATIVES TABLE IS ADDED ALONGSIDE, NOT FOUND. This schema keeps thumbnails
         * in a JSON blob on the file row, which the existing screens read. The added table
         * carries what a blob cannot: the STATE and the ERROR of a derivative, and therefore
         * the ability to regenerate a single one.
         */
        'conversions' => 'mediahub_conversions',

        /*
         * ⚠️ ATTACHMENTS ARE NOT INVENTED HERE. This schema scatters half a dozen `*_media_id`
         * columns across the host application; replacing them is a separate piece of work, and
         * creating the table now would leave it empty for weeks while suggesting it is in use.
         */
        'mediables' => null,
    ],

    /*
     * ⚠️ THE EXPOSED KEY IS THE PRIMARY KEY, FOR WANT OF A UUID COLUMN, and the consequence has
     * to be said out loud: defence against enumeration then rests ENTIRELY on scoping. It is
     * not negotiable either — existing clients send numeric identifiers, and a couple of dozen
     * foreign keys in the host application point at that column.
     */
    'route_key' => 'id',

    /** The root is written as `0` in this schema, not `null`. */
    'root_folder' => 0,

    /*
     * ⚠️ THE DERIVATIVE MIRROR IS A TRANSITION MEASURE. While the previous module still runs,
     * it is the one displaying thumbnails, and it reads them from exactly one place: a `thumb`
     * key in the file's JSON property blob, holding a path relative to the file's disk. A
     * derivative recorded only in this package's own table would be invisible everywhere the
     * user currently looks.
     *
     * ⚠️ THE KEY IS NOT ARBITRARY: the host declares a single thumbnail size, 256×256, which is
     * exactly what this package produces by default. The two overlap, so the mirror costs
     * nothing to keep in step.
     *
     * ⚠️ AND IT IS REMOVED BY EMPTYING THIS LIST once the previous module is gone. That is what
     * makes it a transition measure rather than a debt: it has an off switch.
     */
    'conversion_mirror' => ['thumb'],

    'visibility' => [
        'private' => 0,
        'public' => 1,
    ],

    'map' => [
        'files' => [
            'path' => 'url',
            'scope_key' => 'organization_id',
            'visibility' => 'is_public',
            'custom_properties' => 'options',

            /*
             * ⚠️ THE OWNER COLUMN IS `NOT NULL` WITH NO DEFAULT, on both tables. It is the one
             * column this package does not know about and the database insists on: an upload
             * that fails to supply it FAILS. It is wired to the owner identifier, and the
             * failure — should a host forget — is loud rather than silent.
             *
             * ⚠️ AND THE OWNER IS NOT POLYMORPHIC HERE. The canonical schema stores a type and
             * an identifier; this one assumes it is always a user. The type has nowhere to go,
             * and is lost.
             */
            'owner_id' => 'user_id',
            'owner_type' => null,

            /* Absent from the schema — derived on read. */
            'uuid' => null,
            'file_name' => null,
            'extension' => null,
            'type' => null,

            /* Absent from the schema — and nothing can reconstruct them. */
            'checksum' => null,
            'width' => null,
            'height' => null,
            'duration' => null,
            'meta' => null,
        ],

        'folders' => [
            'scope_key' => 'organization_id',
            'visibility' => 'is_public',

            'owner_id' => 'user_id',
            'owner_type' => null,

            'uuid' => null,

            /*
             * ⚠️ NEITHER A MATERIALISED PATH NOR A DEPTH. The tree is walked upwards through the
             * parent key, which costs one query per level — acceptable, because folders are
             * counted in dozens. The trade is a pleasant one: renaming or moving a folder has
             * NO descendants to rewrite, since nothing derived needs keeping in step.
             */
            'path' => null,
            'depth' => null,
            'meta' => null,
        ],
    ],
];
