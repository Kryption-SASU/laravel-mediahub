<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Concerns\HasMedia;
use Kryption\MediaHub\Support\MediaCollections;

/**
 * A MODEL BELONGING TO THE HOST, NOT TO THE PACKAGE.
 *
 * ⚠️ THAT DISTINCTION IS THE WHOLE TEST. The trait has to work on a table the package has never
 * heard of, with a name it did not choose, in a namespace it does not own. A fixture built out
 * of one of the package's own models would prove nothing about a host — and the package's models
 * already carry behaviour of their own that could carry the test.
 *
 * ⚠️ AND ITS COLLECTIONS COVER EVERY RULE, deliberately. A fixture declaring only the easy ones
 * leaves the others enforced by nothing but their own code.
 */
class HostModel extends Model
{
    use HasMedia;

    protected $table = 'host_articles';

    protected $guarded = [];

    public function registerMediaCollections(MediaCollections $collections): void
    {
        $collections->add('cover')->single()->accepts('image/*')->maxSize(4096);
        $collections->add('attachments');
        $collections->add('brochure')->accepts('application/pdf');
        $collections->add('tiny')->maxSize(1);
        $collections->add('avatar')->single()->fallback('https://example.test/anonymous.png');
    }

    public static function createTable(): void
    {
        Schema::create('host_articles', static function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    public static function dropTable(): void
    {
        Schema::dropIfExists('host_articles');
    }
}
