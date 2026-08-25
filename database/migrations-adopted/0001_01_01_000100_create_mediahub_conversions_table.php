<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Exceptions\StorageMisconfigured;

/**
 * THE CONVERSIONS TABLE, FOR AN ADOPTED SCHEMA THAT HAS NONE.
 *
 * ⚠️ IT IS ADDED, IT REPLACES NOTHING. The target schema keeps its thumbnails in the
 * `media_files.options` JSON blob, which the current screen reads in order to display them.
 * This table brings what the blob cannot carry — a derivative's STATE, its ERROR, and therefore
 * the ability to regenerate a single one — and the package keeps writing the blob as a mirror
 * for as long as the old module runs. Two writes, one truth: the blob is a reflection, never a
 * source.
 *
 * ⚠️ AND NO FOREIGN KEY TO THE HOST'S TABLE, deliberately. Three reasons, in order: the package
 * does not own that table; the type of its key varies from one adopted schema to the next, and
 * MariaDB demands an EXACT match — a `bigint` referencing an `int` fails at creation; and
 * permanent deletion already removes the derivative rows explicitly, without relying on a
 * cascade.
 *
 * ⚠️ THE PRICE IS WRITTEN DOWN: a deletion performed by THE OLD module leaves an orphaned
 * derivative row. The BYTES, however, are properly cleaned up — because the old module erases
 * the files listed in `options`, and therefore ours along with them. That is a happy side
 * effect of the mirror, and one more argument for maintaining it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = HostSchema::tableOrNull('conversions');

        /*
         * ⚠️ WITHOUT A TABLE NAME THERE IS NOTHING TO CREATE. A host may decide to do without
         * derivatives: it sets `null`, and this migration does nothing rather than inventing a
         * table under a prefix it is not expecting.
         */
        if ($table === null) {
            return;
        }

        if (Schema::hasTable($table) && ! self::reclaim($table)) {
            return;
        }

        $keyType = (string) config('mediahub.backend.key_type', 'int');

        Schema::create($table, static function (Blueprint $blueprint) use ($keyType): void {
            $blueprint->id();

            /*
             * ⚠️ THE TYPE FOLLOWS THE HOST'S KEY. Legacy schemas have `int unsigned` keys;
             * putting a `bigint` there would rule out any later join and would waste half the
             * index.
             */
            $keyType === 'bigint'
                ? $blueprint->unsignedBigInteger('media_id')
                : $blueprint->unsignedInteger('media_id');

            $blueprint->string('name');
            $blueprint->string('disk');
            $blueprint->string('path', 1024);
            $blueprint->string('mime_type', 191)->nullable();
            $blueprint->unsignedInteger('width')->nullable();
            $blueprint->unsignedInteger('height')->nullable();
            $blueprint->unsignedBigInteger('size')->default(0);

            $blueprint->string('state')->default('pending');
            $blueprint->text('error')->nullable();

            $blueprint->timestamps();

            $blueprint->unique(['media_id', 'name']);
        });
    }

    /**
     * THE ONE TABLE THE TWO MODES SHARE A NAME FOR — and only one of the two shapes works here.
     *
     * ⚠️ THIS IS WHY A DRIVER SWITCH IS NOT FREE, THOUGH IT LOOKS AS IF IT SHOULD BE. Changing
     * `backend.driver` to `table` does leave `mediahub_files`, `mediahub_folders` and
     * `mediahub_mediables` unused and harmless — nothing reads them again. But `conversions` is
     * needed in BOTH modes and is named the same in both: standalone gives it a `bigint` key and
     * a foreign key onto `mediahub_files`, and here the media live in the HOST's table, so that
     * key points at rows that will never exist. The table is not left behind, it is still in
     * use, and it is wrong.
     *
     * ⚠️ AND THE ORDINARY INSTALLATION ORDER PRODUCES EXACTLY THAT. The package ships with
     * `standalone`, so `composer require` then `migrate` — before anyone has set the driver —
     * creates the whole standalone schema. Measured on a real host on 25/08/2026: every upload
     * of an image failed on a constraint violation, days after the migration that caused it.
     *
     * ⚠️ SO IT TAKES THE TABLE OVER WHEN NOTHING IS IN IT, and only raises when there is. An
     * empty leftover is exactly what the person switching drivers expects not to have to think
     * about; a populated one is a library of derivatives, and dropping it is not a decision a
     * migration gets to take on somebody's behalf.
     *
     * @return bool Whether the caller should now create the table.
     */
    private static function reclaim(string $table): bool
    {
        /* ⚠️ NO FOREIGN KEY MEANS THIS MIGRATION MADE IT: running twice must change nothing. */
        if (Schema::getForeignKeys($table) === []) {
            return false;
        }

        $rows = DB::table($table)->count();

        if ($rows > 0) {
            throw StorageMisconfigured::because(
                "`{$table}` already exists, carries a foreign key and holds {$rows} rows, so it "
                .'was created by the standalone migration and has been used. In `table` mode the '
                ."media live in the host's own table, so that key points at rows that do not "
                .'exist there and every conversion insert fails. Decide what those rows are '
                ."worth: they belong to the standalone library. Drop `{$table}` once you are "
                .'sure, then migrate again.'
            );
        }

        Schema::drop($table);

        return true;
    }

    public function down(): void
    {
        $table = HostSchema::tableOrNull('conversions');

        if ($table !== null) {
            Schema::dropIfExists($table);
        }
    }
};
