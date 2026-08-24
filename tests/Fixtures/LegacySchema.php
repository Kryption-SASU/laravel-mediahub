<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LEGACY SCHEMA AS IT EXISTS IN A LIVE DATABASE, read from `information_schema`.
 *
 * ⚠️ READ FROM `information_schema`, NOT FROM THE MIGRATIONS, and the two do not say the same
 * thing. At least three discrepancies: the `disk` column appears in no migration and exists on
 * both tables; `parent_id` is declared unsigned in the code and is SIGNED in the database; and
 * no foreign key links `media_files.folder_id` to `media_folders`, though reading the migrations
 * would suggest otherwise.
 *
 * ⚠️ THE ABSENCES ARE REPRODUCED AS FAITHFULLY AS THE PRESENCES. No foreign key on `folder_id`,
 * no index on `deleted_at`: a bench adding them for convenience would test a healthier schema
 * than the target, and would declare sound an adoption that breaks in production.
 */
final class LegacySchema
{
    /**
     * THE EXACT LIST OF COLUMNS, in the order the database returns them.
     *
     * ⚠️ THIS IS A WITNESS, AND IT HAS A PRECISE ROLE: every column the preset claims to read
     * must be found here. A typo in the mapping would otherwise only show on the first query,
     * against a schema no bench has to hand.
     *
     * @var array<int, string>
     */
    public const FILE_COLUMNS = [
        'id', 'user_id', 'organization_id', 'name', 'folder_id', 'mime_type', 'size',
        'url', 'disk', 'focus', 'options', 'is_public', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** @var array<int, string> */
    public const FOLDER_COLUMNS = [
        'id', 'user_id', 'organization_id', 'name', 'slug', 'disk', 'parent_id',
        'is_public', 'created_at', 'updated_at', 'deleted_at',
    ];

    public static function create(): void
    {
        Schema::create('media_folders', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('organization_id')->nullable();
            $table->string('name', 191)->nullable();
            $table->string('slug', 191)->nullable();
            $table->string('disk', 191)->nullable();

            /* ⚠️ SIGNED, AND `NOT NULL DEFAULT 0`: the root is written as zero, not `null`. */
            $table->integer('parent_id')->default(0);

            $table->tinyInteger('is_public')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('organization_id');
        });

        Schema::create('media_files', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('organization_id')->nullable();
            $table->string('name', 255);

            /* ⚠️ UNSIGNED HERE, SIGNED ON FOLDERS. The schema is not consistent with itself. */
            $table->unsignedInteger('folder_id')->default(0);

            $table->string('mime_type', 120);

            /* ⚠️ SIGNED: the ceiling is two gibibytes per file, and nothing says so. */
            $table->integer('size');

            /* ⚠️ DESPITE ITS NAME, THIS IS A RELATIVE PATH. Verified on the real rows. */
            $table->string('url', 255);

            $table->string('disk', 191)->nullable();
            $table->string('focus', 255)->nullable();
            $table->text('options')->nullable();
            $table->tinyInteger('is_public')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('organization_id');
        });
    }

    public static function drop(): void
    {
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('media_folders');
    }
}
