<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Contracts\MediaScope;
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

        /*
         * ⚠️ A SCOPE THAT ACTUALLY CONSTRAINS, because the package's own default constrains
         * nothing. Left on that one, every bench here passes with or without the command's way
         * out of scoping — and the fault it exists to prevent, a maintenance command seeing an
         * empty library on a real installation, is exactly the one it could not see.
         *
         * ⚠️ AND `currentKey()` IS NULL, WHICH IS WHAT A TERMINAL HAS. That is not a simplification:
         * a command line has no request, no session and no customer, so the scope resolves to
         * nothing and the constraint hides every file that belongs to somebody.
         */
        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return null;
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', $this->currentKey());
            }
        });
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

    /**
     * ⚠️ A MEDIA IS INVISIBLE OUTSIDE ITS OWN SCOPE, AND A TERMINAL HAS NONE. Written without the
     * named way out, the command answered "0 file(s) built" on an installation holding fifty-three
     * of them — measured, on a real one, the day it shipped. It looked like there was nothing to
     * do, which is the worst thing a maintenance command can look like.
     */
    public function test_the_command_sees_what_a_terminal_has_no_scope_for(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        /* What every scoped installation looks like from a command line. */
        $media->forceFill(['scope_key' => 'some-customer'])->save();

        MediaConversion::query()->delete();

        /* ⚠️ THE EMPTINESS IS ASSERTED FIRST. Without it, a command that built nothing would be
         * indistinguishable from one whose deletion had not happened, and the bench would pass
         * on the fault it exists to catch. */
        $this->assertSame(0, MediaConversion::query()->count(), 'The table was not emptied.');

        /*
         * ⚠️ AND THE FILE IS ASSERTED INVISIBLE, which is the premise of the whole test. Without
         * this line the bench passed with the way out of scoping REMOVED — because nothing had
         * checked that the scope was hiding anything in the first place, and a scope that hides
         * nothing makes the two behaviours identical.
         */
        $this->assertSame(0, Media::query()->count(), 'The scope was not hiding the file.');

        $this->artisan('mediahub:conversions', ['--missing' => true])->assertSuccessful();

        $this->assertSame(1, MediaConversion::query()->where('media_id', $media->getKey())->count());
    }

    /** ⚠️ AND IT SAYS SO, because crossing every scope is not something to find out from a
     * customer. */
    public function test_the_command_says_it_is_crossing_every_scope(): void
    {
        $this->artisan('mediahub:conversions')
            ->expectsOutputToContain('every scope')
            ->assertSuccessful();
    }

    /** ⚠️ AND ONE SCOPE CAN BE NAMED, for an operator who means one customer. */
    public function test_the_command_can_be_narrowed_to_one_scope(): void
    {
        $mine = $this->upload(SampleImages::bytes('image/png'), 'mine.png');
        $theirs = $this->upload(SampleImages::bytes('image/jpeg'), 'theirs.jpg');

        $mine->forceFill(['scope_key' => 'mine'])->save();
        $theirs->forceFill(['scope_key' => 'theirs'])->save();

        MediaConversion::query()->delete();

        $this->artisan('mediahub:conversions', ['--scope' => 'mine'])->assertSuccessful();

        $this->assertSame(1, MediaConversion::query()->where('media_id', $mine->getKey())->count());
        $this->assertSame(0, MediaConversion::query()->where('media_id', $theirs->getKey())->count());
    }

    /**
     * ⚠️ AND THE TRASH IS LEFT ALONE. The named way out takes EVERY global scope with it, soft
     * deletion included: rebuilding the thumbnails of files somebody is in the middle of throwing
     * away is work nobody asked for, on the storage they are trying to free.
     */
    public function test_the_command_leaves_the_trash_alone(): void
    {
        $media = $this->upload(SampleImages::bytes('image/png'), 'photo.png');

        MediaConversion::query()->delete();
        $media->delete();

        $this->artisan('mediahub:conversions', ['--missing' => true])->assertSuccessful();

        $this->assertSame(0, MediaConversion::query()->count());
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
