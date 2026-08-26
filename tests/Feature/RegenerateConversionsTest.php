<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Support\ExternalTools;
use Kryption\MediaHub\Tests\Fixtures\SampleImages;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * BUILDING DERIVATIVES AGAIN, FROM A MENU AND FROM A TERMINAL.
 *
 * ⚠️ THEY ARE MADE ONCE, AT UPLOAD, which is right and leaves a gap: a library that predates the
 * tool drawing its thumbnails has none for anything already in it. The alternative was uploading
 * every file a second time — doubling the storage and changing every identifier.
 */
class RegenerateConversionsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config()->set('mediahub.storage.disk', 'media');
        config()->set('mediahub.routes.middleware', ['web']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);
    }

    private function upload(string $bytes, string $name): Media
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return $this->app->make(UploadMedia::class)(UploadedPayload::fromLocalFile($path, $name));
    }

    // ── Asked for from the screen ────────────────────────────────────────────

    public function test_it_builds_them_again_for_one_file(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        MediaConversion::query()->delete();

        $this->postJson('/media/'.$media->uuid.'/conversions')->assertOk();

        $this->assertSame(1, MediaConversion::query()->where('media_id', $media->getKey())->count());
    }

    /**
     * ⚠️ REFUSED RATHER THAN ANSWERED WITH NOTHING. "Done, and there is still no picture" is
     * indistinguishable from a failure on screen; a reason says which of the two it is — this
     * machine has no tool for that type, or that type has no picture in it.
     */
    public function test_a_type_nothing_here_can_draw_is_refused_with_a_reason(): void
    {
        $media = $this->upload('a plain note', 'notes.txt');

        $this->postJson('/media/'.$media->uuid.'/conversions')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'conversion_unsupported_here');
    }

    // ── What the screen is allowed to offer ──────────────────────────────────

    /**
     * ⚠️ WHETHER A PICTURE COULD BE DRAWN IS NOT A PROPERTY OF THE TYPE. The same `video/mp4` is
     * drawable on a machine with ffmpeg and not on one without, and the browser has no way of
     * working that out — so the server says, per file, and the menu obeys.
     */
    public function test_the_payload_says_whether_anything_could_be_drawn(): void
    {
        $drawable = $this->upload(SampleImages::bytes('image/png'), 'photo.png');
        $not = $this->upload('a plain note', 'notes.txt');

        $this->getJson('/media/'.$drawable->uuid)->assertOk()->assertJsonPath('data.can_draw', true);
        $this->getJson('/media/'.$not->uuid)->assertOk()->assertJsonPath('data.can_draw', false);
    }

    // ── Asked for from a terminal ────────────────────────────────────────────

    /**
     * ⚠️ IT SKIPS WHAT ALREADY HAS ONE. Redoing thirty thousand files to reach the four hundred
     * that need it is hours of processor time and thirty thousand writes to object storage, for
     * an answer identical to what was already there.
     */
    public function test_the_command_leaves_alone_what_already_has_one(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        $this->assertSame(1, MediaConversion::query()->count());

        $this->artisan('mediahub:conversions', ['--missing' => true])
            ->expectsOutputToContain('1 already had one')
            ->assertSuccessful();
    }

    /** ⚠️ AND IT DOES THE WORK WHERE THERE IS NONE, which is the whole point of the command. */
    public function test_the_command_builds_what_is_missing(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        MediaConversion::query()->delete();

        $this->artisan('mediahub:conversions', ['--missing' => true])->assertSuccessful();

        $this->assertSame(1, MediaConversion::query()->where('media_id', $media->getKey())->count());
    }

    /**
     * ⚠️ A ROW LEFT AT `failed` IS EXACTLY WHAT SOMEBODY RUNNING THIS WANTS TO RETRY. Treating it
     * as "already has one" would make `--missing` useless on the files it was written for.
     */
    public function test_a_failed_row_counts_as_missing(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        MediaConversion::query()->update(['state' => ConversionState::Failed->value]);

        $this->artisan('mediahub:conversions', ['--missing' => true])->assertSuccessful();

        $this->assertSame(
            ConversionState::Ready,
            MediaConversion::query()->where('media_id', $media->getKey())->first()->state,
        );
    }

    /**
     * ⚠️ WHAT CANNOT BE DRAWN IS NAMED, NOT SWALLOWED. "Nothing can be drawn for these" is the
     * answer to the question somebody asks next — why is that folder still all icons — and a
     * silent skip sends them to the code instead.
     */
    public function test_the_command_names_what_nothing_here_can_draw(): void
    {
        $this->upload('a plain note', 'notes.txt');

        $this->artisan('mediahub:conversions')
            ->expectsOutputToContain('text/plain')
            ->assertSuccessful();
    }

    /** ⚠️ AND IT STOPS WHERE IT WAS TOLD TO, so a first run on a large library can be a sample. */
    public function test_the_command_stops_at_the_limit(): void
    {
        $this->upload(SampleImages::bytes('image/png'), 'one.png');
        $this->upload(SampleImages::bytes('image/jpeg'), 'two.jpg');

        MediaConversion::query()->delete();

        $this->artisan('mediahub:conversions', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(1, MediaConversion::query()->count());
    }
}
