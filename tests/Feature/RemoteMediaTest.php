<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Kryption\MediaHub\Concerns\HasMedia;
use Kryption\MediaHub\Contracts\RemoteFetcher;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Support\MediaCollections;
use Kryption\MediaHub\Tests\TestCase;

/**
 * ATTACHING A FILE FROM A URL.
 *
 * ⚠️ THE INTERESTING CASES ARE THE REFUSALS. The address rules are proven in isolation, without
 * a network; what is left to check here is that the door is shut unless somebody opened it, that
 * what comes back is treated as an upload rather than as something trusted, and that nothing is
 * left on the disk when it goes wrong.
 */
class RemoteMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-remote-media',
            'serve' => false,
            'throw' => false,
        ]);

        $app['config']->set('mediahub.storage.disk', 'media');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('articles', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory(sys_get_temp_dir().'/mediahub-remote-media');

        parent::tearDown();
    }

    private function subject(): Model
    {
        return RemoteArticle::create();
    }

    /** Stands in for the network, and records the file it handed over. */
    private function serve(string $bytes, string $name = 'photo.png'): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mediahub-remote');

        file_put_contents($path, $bytes);

        $this->app->bind(RemoteFetcher::class, static fn (): RemoteFetcher => new class($path) implements RemoteFetcher
        {
            public function __construct(private readonly string $path)
            {
            }

            public function fetch(string $url): string
            {
                return $this->path;
            }
        });

        return $path;
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    /**
     * ⚠️ SHUT UNLESS SOMEBODY OPENED IT. An installation that never fetches remote files should
     * not carry the risk of the feature, and the refusal names the setting rather than failing
     * to build an object somewhere in the container.
     */
    public function test_it_is_off_until_the_host_turns_it_on(): void
    {
        $this->serve($this->png());

        try {
            $this->subject()->addMediaFromUrl('https://example.com/photo.png');
            self::fail('the fetch should have been refused');
        } catch (OperationRejected $rejected) {
            self::assertSame('remote_disabled', $rejected->reason);
        }
    }

    public function test_it_attaches_what_came_back(): void
    {
        config()->set('mediahub.remote.enabled', true);

        $this->serve($this->png());

        $media = $this->subject()->addMediaFromUrl('https://example.com/photo.png');

        self::assertSame('photo.png', $media->file_name);
        self::assertSame('image/png', $media->mime_type);
    }

    /**
     * ⚠️ AN ADDRESS THAT NAMES NO FILE IS REFUSED FOR THAT, AND SAYS SO. Inventing a name
     * without an extension gets it rejected further down as `extension_not_allowed`, which reads
     * as "that image is invalid" when the truth is "that address does not say what it is called".
     */
    public function test_it_refuses_an_address_that_names_no_file(): void
    {
        config()->set('mediahub.remote.enabled', true);

        $this->serve($this->png());

        try {
            $this->subject()->addMediaFromUrl('https://example.com/holidays/');
            self::fail('an address naming no file should be refused');
        } catch (OperationRejected $rejected) {
            self::assertSame('remote_unnamed', $rejected->reason);
        }
    }

    public function test_the_caller_can_name_it_instead(): void
    {
        config()->set('mediahub.remote.enabled', true);

        $this->serve($this->png());

        $media = $this->subject()->addMediaFromUrl('https://example.com/whatever/', 'default', 'chosen.png');

        self::assertSame('chosen.png', $media->file_name);
    }

    /**
     * ⚠️ WHAT CAME BACK IS NOT TRUSTED. A remote server does not get to decide what its file is
     * by naming it: the collection's rules are checked on the real bytes, exactly as for an
     * upload.
     */
    public function test_the_collection_rules_apply_to_what_came_back(): void
    {
        config()->set('mediahub.remote.enabled', true);

        $this->serve('not an image at all', 'photo.png');

        try {
            $this->subject()->addMediaFromUrl('https://example.com/photo.png', 'pictures');
            self::fail('a text file should not satisfy a collection that accepts images');
        } catch (OperationRejected $rejected) {
            self::assertNotSame('', $rejected->reason);
        }
    }

    /**
     * ⚠️ AND THE TEMPORARY FILE GOES, WHATEVER HAPPENED. A refused type, a full quota, a
     * collection that says no: every one of those leaves bytes behind if the cleanup only runs
     * on the happy path, and nothing ever comes back to sweep them.
     */
    public function test_it_leaves_nothing_behind_when_the_upload_is_refused(): void
    {
        config()->set('mediahub.remote.enabled', true);

        $path = $this->serve('not an image at all', 'photo.png');

        try {
            $this->subject()->addMediaFromUrl('https://example.com/photo.png', 'pictures');
        } catch (OperationRejected) {
            /* expected */
        }

        self::assertFileDoesNotExist($path);
    }

    public function test_it_leaves_nothing_behind_when_it_succeeds(): void
    {
        config()->set('mediahub.remote.enabled', true);

        $path = $this->serve($this->png());

        $this->subject()->addMediaFromUrl('https://example.com/photo.png');

        self::assertFileDoesNotExist($path);
    }
}

class RemoteArticle extends Model
{
    use HasMedia;

    protected $table = 'articles';

    protected $guarded = [];

    public function registerMediaCollections(MediaCollections $collections): void
    {
        $collections->add('pictures')->accepts('image/*');
    }
}
