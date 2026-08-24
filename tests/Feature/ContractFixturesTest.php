<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE BRIDGE BETWEEN THE SERVER AND THE TYPES THE BROWSER BELIEVES IN.
 *
 * ⚠️ A TYPESCRIPT INTERFACE IS A CLAIM ABOUT ANOTHER PROGRAM, and nothing in a TypeScript suite
 * can check it. Both sides stay green while they drift apart: the server renames a key, the
 * browser keeps reading the old one, and `undefined` travels quietly until it reaches a screen.
 *
 * ⚠️ SO THE FIXTURES ARE WRITTEN BY THE SERVER, FROM REAL RESPONSES, and committed. The browser
 * suite reads those files instead of inventing payloads; this test regenerates them and refuses
 * any change of SHAPE. The two suites can then only disagree by turning this one red first.
 *
 * ⚠️ SHAPE, NOT VALUES. Comparing payloads literally would fail on every identifier and every
 * timestamp, and would be silenced within a week. What is compared is the set of keys and the
 * type behind each — which is exactly what an interface promises.
 *
 * To regenerate after a deliberate change:
 *
 *     MEDIAHUB_WRITE_CONTRACT=1 vendor/bin/phpunit --filter ContractFixturesTest
 *
 * ⚠️ AND THEN READ THE DIFF. Regenerating without looking turns this guard into a rubber stamp.
 */
class ContractFixturesTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '00000000-0000-4000-8000-000000000000';

    private const MOMENT = '2026-01-01T00:00:00+00:00';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);

        /*
         * ⚠️ A REAL LOCAL DISK, NOT `Storage::fake()` — the same reason as everywhere else on
         * this bench: the fake disk brings its own temporary-URL builder, so the addresses in
         * these fixtures would not be the ones production serves. A contract file describing
         * URLs nobody receives is worse than no contract file.
         */
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-contract',
            'serve' => false,
            'throw' => false,
        ]);

        $app['config']->set('mediahub.storage.disk', 'media');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['files']->deleteDirectory($this->root());
        $this->app['files']->ensureDirectoryExists($this->root());
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->root());

        parent::tearDown();
    }

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-contract';
    }

    private function directory(): string
    {
        return __DIR__.'/../Fixtures/contract';
    }

    /**
     * ⚠️ FULLY POPULATED ON PURPOSE. A media left with `width` at `null` would freeze `null` as
     * the expected type, and the first response carrying a real number would fail a test that
     * has found nothing wrong. Only what is genuinely empty here stays empty — a thumbnail no
     * queue has built, a deletion that never happened.
     */
    private function media(?MediaFolder $folder = null): Media
    {
        $media = Media::create([
            'folder_id' => $folder?->getKey(),
            'disk' => 'media',
            'path' => '2026/08/annual-report.pdf',
            'name' => 'Annual report',
            'file_name' => 'annual-report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 182_311,
            'width' => 1240,
            'height' => 1754,
            'duration' => 0,
            'checksum' => str_repeat('c', 64),
            'custom_properties' => ['alt' => 'The 2026 annual report'],
        ]);

        Storage::disk('media')->put((string) $media->path, 'some bytes');

        return $media;
    }

    private function folder(string $name): MediaFolder
    {
        return $this->app->make(CreateFolder::class)($name);
    }

    public function test_the_browse_payload_keeps_its_shape(): void
    {
        $folder = $this->folder('Invoices');
        $this->media($folder);
        $this->media();

        $this->assertShape('browse-root', $this->getJson('/media')->assertOk()->json());
        $this->assertShape('browse-folder', $this->getJson('/media?folder='.$folder->uuid)->assertOk()->json());
    }

    public function test_the_media_payload_keeps_its_shape(): void
    {
        $media = $this->media();

        $this->assertShape('media', $this->getJson('/media/'.$media->uuid)->assertOk()->json());
    }

    public function test_the_folder_payload_keeps_its_shape(): void
    {
        $this->assertShape(
            'folder',
            $this->postJson('/media/folders', ['name' => 'Contracts'])->assertStatus(201)->json(),
        );
    }

    public function test_the_quota_payload_keeps_its_shape(): void
    {
        $this->assertShape('quota', $this->getJson('/media/quota')->assertOk()->json());
    }

    public function test_the_batch_payload_keeps_its_shape(): void
    {
        $media = $this->media();

        $this->assertShape(
            'batch',
            $this->postJson('/media/trash', ['media' => [$media->uuid]])->assertOk()->json(),
        );
    }

    /**
     * ⚠️ THE REFUSALS ARE PART OF THE CONTRACT TOO, and they are the half a client actually
     * branches on. A `reason` that stopped being sent would break every screen rendering its own
     * wording, and no test on either side would notice.
     */
    public function test_a_refusal_keeps_its_shape(): void
    {
        $this->assertShape(
            'refusal',
            $this->postJson('/media/archive', ['folders' => [$this->folder('Empty')->uuid]])
                ->assertStatus(422)
                ->json(),
        );
    }

    public function test_a_missing_item_keeps_its_shape(): void
    {
        $this->assertShape(
            'not-found',
            $this->postJson('/media/trash', ['media' => ['nope']])->assertNotFound()->json(),
        );
    }

    /**
     * ⚠️ A VALIDATION FAILURE HAS ANOTHER SHAPE ENTIRELY — it comes from the framework, carries
     * `errors` and no `reason`. A client assuming one shape for every failure reads `undefined`
     * exactly when it is trying to explain what went wrong.
     */
    public function test_a_validation_failure_keeps_its_shape(): void
    {
        $this->assertShape(
            'invalid',
            $this->postJson('/media/folders', [])->assertStatus(422)->json(),
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function assertShape(string $name, array $payload): void
    {
        $path = $this->directory().'/'.$name.'.json';
        $scrubbed = $this->scrub($payload);

        if (getenv('MEDIAHUB_WRITE_CONTRACT') !== false) {
            $this->app['files']->ensureDirectoryExists($this->directory());

            file_put_contents(
                $path,
                json_encode($scrubbed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );

            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists($path, sprintf(
            'No contract fixture for "%s". Regenerate with MEDIAHUB_WRITE_CONTRACT=1.',
            $name,
        ));

        $committed = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            $this->shape($committed),
            $this->shape($scrubbed),
            sprintf(
                'The "%s" response no longer has the shape the browser expects. If the change is '
                    .'deliberate, regenerate with MEDIAHUB_WRITE_CONTRACT=1 and update the TypeScript types.',
                $name,
            ),
        );
    }

    /**
     * WHAT A TYPE ACTUALLY PROMISES: which keys exist, and what each holds.
     *
     * ⚠️ A LIST IS DESCRIBED BY ITS FIRST ITEM. Walking all of them would catch a heterogeneous
     * list, which none of these payloads has; describing none of them would let a list of
     * objects become a list of strings unnoticed.
     */
    private function shape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }

        if (array_is_list($value)) {
            return $value === [] ? [] : [$this->shape($value[0])];
        }

        return array_map(fn (mixed $item): mixed => $this->shape($item), $value);
    }

    /**
     * ⚠️ SIGNATURES, IDENTIFIERS AND TIMESTAMPS CHANGE ON EVERY RUN. Left in, the committed file
     * would differ from itself and regenerating would produce noise nobody reads — which is how
     * a guard stops being read at all.
     */
    private function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->scrub($item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $scrubbed = (string) preg_replace('/(expires|signature)=[^&]*/', '$1=', $value);

        $scrubbed = (string) preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            self::UUID,
            $scrubbed,
        );

        return (string) preg_replace(
            '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?/',
            self::MOMENT,
            $scrubbed,
        );
    }
}
