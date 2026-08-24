<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE TABLES OF STANDALONE MODE.
 *
 * ⚠️ THEY ARE ONLY CREATED IN THAT MODE. A host plugging the package onto existing tables does
 * not want them — that is the very reason `table` mode exists.
 *
 * ⚠️ NO FOREIGN KEY TO A MODEL THE PACKAGE DOES NOT KNOW. The owner is polymorphic and
 * optional: the package has no idea whether your application has a `users` table, whether it is
 * called that, or whether its key is an integer. The module it replaces added a column to its
 * host's users table — a package has no business writing into a table that is not its own.
 *
 * ⚠️ AND THE PREFIX IS CONFIGURABLE, so it can coexist with an already installed library for
 * the duration of a transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        $p = $this->prefix();

        Schema::create($p.'folders', function (Blueprint $table) use ($p): void {
            $table->id();

            /*
             * ⚠️ THE EXPOSED IDENTIFIER IS NOT THE DATABASE ONE. A sequential identifier in a
             * URL is an invitation to enumerate — and that is exactly the vector of a flaw
             * observed in the original module, where raw identifiers were enough to act on
             * another customer's files.
             */
            $table->uuid('uuid')->unique();

            $table->foreignId('parent_id')->nullable()
                ->constrained($p.'folders')->nullOnDelete();

            $table->string('name');
            $table->string('slug');

            /*
             * The materialised path: it saves walking back up the tree on every display.
             * ⚠️ IT IS DERIVED, THEREFORE IT CAN LIE after a move left half finished — hence
             * its index, but never its uniqueness.
             */
            $table->string('path', 1024)->nullable();
            $table->unsignedSmallInteger('depth')->default(0);

            $table->string('scope_key')->nullable();
            $table->nullableMorphs('owner');
            $table->string('visibility')->default('private');
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_key', 'parent_id', 'deleted_at']);
        });

        Schema::create($p.'files', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('folder_id')->nullable()
                ->constrained($p.'folders')->nullOnDelete();

            /*
             * ⚠️ THE DISK IS RECORDED ON EVERY MEDIA, and the path is RELATIVE to it. Never a
             * URL: that is computed on read. It is what makes it possible to change storage, to
             * move behind a cache or to turn signing on without migrating a single row — and it
             * is what the original module lacked, where one read tested the existence of a
             * local file starting from a remote URL.
             */
            $table->string('disk');
            $table->string('path', 1024);

            /* The DISPLAYED name, which the person can change. */
            $table->string('name');

            /* The name ON DISK, normalised. Confusing the two is a mistake. */
            $table->string('file_name');

            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 191);
            $table->string('type', 32);
            $table->unsignedBigInteger('size')->default(0);

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();

            /* The content checksum: a duplicate is judged on this, not on the name. */
            $table->char('checksum', 64)->nullable();

            $table->json('custom_properties')->nullable();
            $table->json('meta')->nullable();

            $table->string('scope_key')->nullable();
            $table->nullableMorphs('owner');
            $table->string('visibility')->default('private');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_key', 'folder_id', 'deleted_at']);
            $table->index(['scope_key', 'type']);
            $table->index(['scope_key', 'checksum']);
        });

        /*
         * ⚠️ DERIVATIVES HAVE THEIR OWN TABLE, THEY ARE NOT A JSON BLOCK. The original module
         * piled them into a catch-all column: no way to know which ones are ready, which have
         * failed, which are orphaned, nor to regenerate a single one. A table makes them
         * queryable, cleanable and regenerable.
         */
        Schema::create($p.'conversions', function (Blueprint $table) use ($p): void {
            $table->id();

            $table->foreignId('media_id')->constrained($p.'files')->cascadeOnDelete();

            $table->string('name');
            $table->string('disk');
            $table->string('path', 1024);
            $table->string('mime_type', 191)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size')->default(0);

            /* ⚠️ THE STATE IS RECORDED: a screen shows a placeholder, not a broken image. */
            $table->string('state')->default('pending');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['media_id', 'name']);
        });

        /*
         * ⚠️ THE GREAT ABSENTEE OF THE ORIGINAL MODULE: attaching a media to a host model. Each
         * project reinvented its own `xxx_media_id` column — six of them coexisted in the
         * application that served as the field.
         */
        Schema::create($p.'mediables', function (Blueprint $table) use ($p): void {
            $table->foreignId('media_id')->constrained($p.'files')->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('collection')->default('default');
            $table->unsignedInteger('position')->default(0);

            $table->primary(['media_id', 'mediable_type', 'mediable_id', 'collection'], 'mediables_primary');
            $table->index(['mediable_type', 'mediable_id', 'collection', 'position'], 'mediables_order');
        });

    }

    public function down(): void
    {
        $p = $this->prefix();

        /* The reverse order of dependencies: whatever references goes first. */
        foreach (['mediables', 'conversions', 'files', 'folders'] as $table) {
            Schema::dropIfExists($p.$table);
        }
    }

    private function prefix(): string
    {
        return (string) config('mediahub.backend.table_prefix', 'mediahub_');
    }
};
