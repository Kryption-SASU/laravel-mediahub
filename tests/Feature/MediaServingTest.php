<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Contracts\UrlGenerator;
use Kryption\MediaHub\Exceptions\UrlSigningFailed;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Tests\TestCase;

/**
 * SERVING A FILE, AND SAYING WHERE IT IS.
 *
 * ⚠️ TWO THINGS ARE TESTED HERE, AND THEY ANSWER EACH OTHER: the URL that gets built, and what
 * happens when it is followed. Testing them separately lets the classic defect through — a
 * perfectly formed URL naming a route that does not exist, or a perfectly working route nobody
 * can name.
 *
 * ⚠️ AND THE BENCH DOES NOT USE `Storage::fake()`, FOR A MEASURED REASON. Laravel's fake disk
 * installs a temporary-URL builder of its own (`?expiration=…`), so that `temporaryUrl()`
 * answers something in tests. Consequence: it CLAIMS to be able to sign. The fallback path — the
 * one that matters for a local disk, namely the package's route — would never have been taken
 * here, and the bench would have certified code nobody runs.
 */
class MediaServingTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $current = null;

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-serving';
    }

    protected function defineEnvironment($app): void
    {
        /*
         * ⚠️ WE REMOVE `auth`, NOT BECAUSE IT GETS IN THE WAY, BUT BECAUSE IT IS NOT THE
         * SUBJECT. The bench has neither a users table nor a configured guard: leaving it would
         * only test Laravel's redirect. A test below separately checks that the SHIPPED default
         * does require it.
         */
        $app['config']->set('mediahub.routes.middleware', ['web']);

        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-serving',
            'serve' => false,
            'throw' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['files']->deleteDirectory($this->root());
        $this->app['files']->ensureDirectoryExists($this->root());

        self::$current = 'org:a';

        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return MediaServingTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', MediaServingTest::key());
            }
        });
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->root());

        parent::tearDown();
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function media(array $attributes = [], string $contents = 'ABCDEFGHIJ'): Media
    {
        $media = Media::create(array_merge([
            'disk' => 'media',
            'path' => '2026/08/report.pdf',
            'name' => 'Annual report',
            'file_name' => 'annual-report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => strlen($contents),
        ], $attributes));

        Storage::disk('media')->put($media->path, $contents);

        return $media;
    }

    private function urls(): UrlGenerator
    {
        return $this->app->make(UrlGenerator::class);
    }

    // ── The URL ──────────────────────────────────────────────────────────────

    public function test_the_inline_url_is_signed_and_expiring(): void
    {
        $url = $this->urls()->url($this->media());

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_the_url_goes_through_the_route_when_the_disk_cannot_sign(): void
    {
        $media = $this->media();

        $this->assertStringContainsString('/media/'.$media->uuid.'/file', $this->urls()->url($media));
    }

    public function test_a_url_signed_by_the_disk_is_returned_as_it_is(): void
    {
        /*
         * ⚠️ NO PARAMETER ADDED. A storage signature covers the ENTIRE query string: hanging the
         * media identifier on it to allow a renewal would invalidate it, and the error would
         * only show at the provider, at runtime.
         */
        Storage::disk('media')->buildTemporaryUrlsUsing(
            fn (string $path, $expiry): string => 'https://objects.test/'.$path.'?sig=abc'
        );

        $this->assertSame(
            'https://objects.test/2026/08/report.pdf?sig=abc',
            $this->urls()->url($this->media())
        );
    }

    public function test_downloading_never_goes_through_the_storage(): void
    {
        /*
         * ⚠️ EVEN WHEN THE STORAGE CAN SIGN. A file name and an attachment header are an HTTP
         * response, not a property of the object.
         */
        Storage::disk('media')->buildTemporaryUrlsUsing(
            fn (string $path, $expiry): string => 'https://objects.test/'.$path
        );

        $media = $this->media();

        $this->assertStringContainsString(
            '/media/'.$media->uuid.'/download',
            $this->urls()->downloadUrl($media)
        );
    }

    public function test_without_signing_the_url_is_the_disks(): void
    {
        $this->app['config']->set('mediahub.urls.signed', false);

        $url = $this->urls()->url($this->media());

        $this->assertStringNotContainsString('signature=', $url);
        $this->assertStringContainsString('2026/08/report.pdf', $url);
    }

    public function test_a_zero_duration_does_not_produce_an_already_expired_link(): void
    {
        /*
         * ⚠️ IT IS NOT ENOUGH FOR `expires` TO BE THERE. A zero duration would produce an expiry
         * equal to the present instant: the parameter would be present, the link unusable, and
         * an assertion on the mere presence of the parameter would stay green.
         */
        $this->app['config']->set('mediahub.urls.ttl', 0);

        parse_str((string) parse_url($this->urls()->url($this->media()), PHP_URL_QUERY), $parameters);

        $this->assertArrayHasKey('expires', $parameters);
        $this->assertGreaterThan(time(), (int) $parameters['expires']);
    }

    public function test_building_a_url_raises_when_the_route_cannot_be_found(): void
    {
        /*
         * ⚠️ THE LOUD FALLBACK. With no usable route and no storage able to sign, the only other
         * possible answer would be the public URL — that is, handing out permanent links to
         * private files, silently, on a screen that works.
         */
        $this->app['config']->set('mediahub.routes.as', 'nonexistent.');

        $this->expectException(UrlSigningFailed::class);

        $this->urls()->url($this->media());
    }

    public function test_the_shipped_route_group_requires_authentication(): void
    {
        /*
         * ⚠️ THIS TEST GUARDS A DEFAULT, NOT A BEHAVIOUR. The bench removes `auth` in order to
         * test the rest; without this assertion, somebody could remove it from the shipped
         * configuration and the whole suite would stay green.
         */
        $shipped = require __DIR__.'/../../config/mediahub.php';

        $this->assertContains('auth', $shipped['routes']['middleware']);
    }

    // ── Serving ──────────────────────────────────────────────────────────────

    public function test_the_file_is_served_inline(): void
    {
        $response = $this->get($this->urls()->url($this->media()));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('ABCDEFGHIJ', $response->streamedContent());
    }

    public function test_the_download_carries_the_displayed_name(): void
    {
        /*
         * ⚠️ THE DISPLAYED NAME, NOT THE ONE ON DISK. The latter is normalised for storage;
         * handing it back to the user gives them a name they never wrote.
         */
        $response = $this->get($this->urls()->downloadUrl($this->media()));

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString('Annual report.pdf', $disposition);
        $this->assertStringNotContainsString('annual-report.pdf', $disposition);
    }

    public function test_a_dangerous_displayed_name_is_sanitised(): void
    {
        /*
         * ⚠️ THIS TEST HAD TO BE REWRITTEN, AND THE REASON IS WORTH READING. It only asserted
         * ABSENCES — no slash, no carriage return in the header. But Symfony REFUSES those
         * characters by raising: without sanitising, the response became an error page, devoid
         * of a `Content-Disposition` header, and both absences were therefore perfectly
         * verified. The mutation removing the sanitising left the suite green. An absence
         * assertion needs a presence assertion beside it, otherwise it certifies the failure
         * just as well.
         */
        $media = $this->media(['name' => "Annu/al\r\nreport"]);

        $response = $this->get($this->urls()->downloadUrl($media));

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString('Annualreport.pdf', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
    }

    // ── Ranges ───────────────────────────────────────────────────────────────

    public function test_a_range_returns_a_206_with_its_content_range(): void
    {
        $response = $this->get($this->urls()->url($this->media()), ['Range' => 'bytes=2-5']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 2-5/10');
        $response->assertHeader('Content-Length', '4');
        $this->assertSame('CDEF', $response->streamedContent());
    }

    public function test_an_open_ended_range_goes_to_the_end(): void
    {
        $response = $this->get($this->urls()->url($this->media()), ['Range' => 'bytes=7-']);

        $response->assertStatus(206);
        $this->assertSame('HIJ', $response->streamedContent());
    }

    public function test_a_suffix_range_returns_the_last_bytes(): void
    {
        $response = $this->get($this->urls()->url($this->media()), ['Range' => 'bytes=-3']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 7-9/10');
        $this->assertSame('HIJ', $response->streamedContent());
    }

    public function test_an_out_of_bounds_range_returns_a_416_that_states_the_size(): void
    {
        $response = $this->get($this->urls()->url($this->media()), ['Range' => 'bytes=50-60']);

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_a_range_starting_just_past_the_end_returns_a_416(): void
    {
        /*
         * ⚠️ THE EDGE CASE, AND IT IS NOT DECORATIVE. A ten-byte file has valid positions from 0
         * to 9: `bytes=10-` names the first byte that does not exist. That is exactly the
         * request made by a client that has already received everything and believes there is
         * more.
         */
        $response = $this->get($this->urls()->url($this->media()), ['Range' => 'bytes=10-']);

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_several_ranges_return_the_whole_file(): void
    {
        $response = $this->get($this->urls()->url($this->media()), ['Range' => 'bytes=0-1,4-5']);

        $response->assertOk();
        $this->assertSame('ABCDEFGHIJ', $response->streamedContent());
    }

    // ── Scoping and the signature ────────────────────────────────────────────

    public function test_a_foreign_media_returns_404(): void
    {
        self::$current = 'org:b';
        $url = $this->urls()->url($this->media());
        self::$current = 'org:a';

        $this->get($url)->assertNotFound();
    }

    public function test_a_forged_signature_is_refused(): void
    {
        $url = $this->urls()->url($this->media());

        $this->get($url.'x')->assertForbidden();
    }

    public function test_an_expired_signature_is_refused(): void
    {
        $this->app['config']->set('mediahub.urls.ttl', 5);

        $url = $this->urls()->url($this->media());

        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        try {
            $this->get($url)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── Derivatives ──────────────────────────────────────────────────────────

    public function test_a_ready_derivative_is_served(): void
    {
        $derivative = $this->conversion($this->media(), 'ready');

        $response = $this->get($this->urls()->conversionUrl($derivative));

        $response->assertOk();
        $this->assertSame('thumbnail', $response->streamedContent());
    }

    public function test_a_derivative_that_is_not_ready_returns_404(): void
    {
        /*
         * ⚠️ SERVING ITS ROW WOULD RETURN AN EMPTY STREAM WITH A 200 STATUS: a broken image
         * nothing distinguishes from a storage failure.
         */
        $derivative = $this->conversion($this->media(), 'failed');

        $this->get($this->urls()->conversionUrl($derivative))->assertNotFound();
    }

    private function conversion(Media $media, string $state): MediaConversion
    {
        Storage::disk('media')->put('2026/08/report-thumb.png', 'thumbnail');

        return MediaConversion::create([
            'media_id' => $media->getKey(),
            'name' => 'thumb',
            'disk' => 'media',
            'path' => '2026/08/report-thumb.png',
            'mime_type' => 'image/png',
            'state' => $state,
            'size' => 9,
        ]);
    }
}
