<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Enums\MediaType;
use Kryption\MediaHub\Exceptions\UploadRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\ExtensionFamilies;
use Kryption\MediaHub\Models\MediaConversion;
use Kryption\MediaHub\Tests\Fixtures\SampleFiles;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * VIDEO AND AUDIO — from every world, and stored as they are.
 *
 * ⚠️ THREE WORLDS THAT DO NOT TALK TO EACH OTHER. An iPhone films in QuickTime, an Android in
 * 3GPP or WebM, a camcorder in AVCHD, Windows in ASF. Accepting only MP4 amounts to refusing
 * half of what people actually have in their hands — and an upload refusal is never explainable
 * to the person on the receiving end.
 *
 * ⚠️ NONE OF THEM IS TRANSFORMED. Not re-encoded, not recompressed, not stripped of its audio
 * track: the bytes uploaded are the bytes served. No thumbnail either — extracting a frame from
 * a video requires an external tool this package does not require, and the module it replaces
 * does no better: it displays a static placeholder.
 *
 * ⚠️ AND THIS FILE KEEPS THE DOOR SHUT. Widening an allow-list is exactly the moment a hole gets
 * reopened: the last tests present an executable document under a video name, and demand that it
 * still be refused.
 */
class VideoFormatsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config()->set('mediahub.storage.disk', 'media');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    private function upload(string $bytes, string $name): Media
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return $this->app->make(UploadMedia::class)(UploadedPayload::fromLocalFile($path, $name));
    }

    // ── What must get through ────────────────────────────────────────────────

    /**
     * A container the running PHP build cannot name is still accepted, and still filed as a
     * video.
     *
     * ⚠️ THIS TEST EXISTS BECAUSE THE BUG IT COVERS ONLY APPEARED ON PHP 8.2 AND 8.3. The
     * signature database is compiled into the `fileinfo` extension, so what it recognises
     * differs between builds: an M2TS stream is named on 8.4 and unknown below. Pinning the
     * behaviour to bytes that NO build recognises makes the rule provable on any runtime,
     * instead of on the two where it happened to break.
     *
     * ⚠️ AND THE FAMILY MATTERS AS MUCH AS THE ACCEPTANCE. Stored but filed as "other", the
     * file is served correctly and yet absent from the screen that lists videos — a
     * difference the user sees, caused by nothing but the runtime.
     */
    public function test_a_container_the_machine_cannot_name_is_still_accepted(): void
    {
        $unknown = str_repeat("\x17\x2b\x5d\x03", 64);

        $media = $this->upload($unknown, 'film.m2ts');

        $this->assertSame(ExtensionFamilies::NO_OPINION, $media->mime_type);
        $this->assertSame(MediaType::Video, $media->mediaType());
        $this->assertSame($unknown, Storage::disk('media')->get((string) $media->path));
    }

    /**
     * ⚠️ THE COUNTER-EXAMPLE, AND IT IS THE HALF THAT PROTECTS. The same leniency granted to
     * an image extension would let a file with an unreadable header through — and the
     * dimension ceiling that guards against decompression bombs only runs on a type starting
     * with `image/`. Unidentifiable plus an image name must stay a refusal.
     */
    public function test_but_an_image_the_machine_cannot_name_is_still_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload(str_repeat("\x17\x2b\x5d\x03", 64), 'photo.png');
    }

    /**
     * ⚠️ THE EXPECTED TYPE IS THE ONE SNIFFING ACTUALLY RETURNED, measured once and written
     * into the sample set. Asserting it — rather than copying it from the code under test —
     * is what lets this test say no: if recognition of a container changes, it falls.
     *
     * ⚠️ WITH ONE RELAXATION, AND IT IS NOT A WEAKENING. The signature database is compiled
     * into the `fileinfo` extension, so it differs between PHP builds: measured, an M2TS
     * stream is recognised on PHP 8.4 and unknown on 8.2 and 8.3. The type assertion is
     * therefore skipped only when the build has NO opinion at all. Whenever it has one, it is
     * held to the letter — a container newly recognised as something else still fails.
     *
     * ⚠️ AND THE FAMILY IS ASSERTED UNCONDITIONALLY. That is the point: whatever the runtime
     * can or cannot name, the file must still be filed as a video, or it vanishes from the
     * screen that lists videos.
     */
    public function test_every_video_container_is_accepted_and_stored_as_it_is(): void
    {
        foreach (SampleFiles::videos() as $extension => [$bytes, $expectedType]) {
            $media = $this->upload($bytes, 'film.'.$extension);

            if ($media->mime_type !== ExtensionFamilies::NO_OPINION) {
                $this->assertSame(
                    strtolower($expectedType),
                    $media->mime_type,
                    $extension.': unexpected recognised type.'
                );
            }
            $this->assertSame(MediaType::Video, $media->mediaType(), $extension.': filed wrongly.');

            $this->assertSame(
                $bytes,
                Storage::disk('media')->get((string) $media->path),
                $extension.': the uploaded bytes were modified.'
            );

            $this->assertSame(
                0,
                MediaConversion::query()->where('media_id', $media->getKey())->count(),
                $extension.': a derivative was built for a video.'
            );
        }
    }

    /**
     * ⚠️ THE `.wma` IS THE CASE THAT JUSTIFIES THE WHOLE TIE-BREAK. It is an ASF container, the
     * same as the `.wmv`: `finfo` answers `video/x-ms-asf` for a purely audio file. Without a
     * tie-break, every WMA would be filed among the videos; with a rule "audio extension ⇒ audio
     * type", they would all be REFUSED.
     */
    public function test_every_audio_container_too_including_the_asf_trap(): void
    {
        foreach (SampleFiles::audios() as $extension => [$bytes, $expectedType]) {
            $media = $this->upload($bytes, 'track.'.$extension);

            $this->assertSame(strtolower($expectedType), $media->mime_type, $extension.': unexpected recognised type.');
            $this->assertSame(MediaType::Audio, $media->mediaType(), $extension.': filed wrongly.');
            $this->assertSame($bytes, Storage::disk('media')->get((string) $media->path), $extension.': bytes modified.');
            $this->assertSame(0, MediaConversion::query()->where('media_id', $media->getKey())->count(), $extension.': derivative built.');
        }
    }

    /**
     * ⚠️ WITHOUT THIS TEST, THE TWO ABOVE WOULD BE SATISFIED BY A RANDOM CLASSIFICATION. We
     * check that the nature does follow the CONTENT when the content is unambiguous: an audio
     * file named `.mp4` is still audio.
     */
    public function test_the_content_stays_the_authority_when_it_is_unambiguous(): void
    {
        $media = $this->upload(SampleFiles::audios()['mp3'][0], 'song.mp3');

        $this->assertSame(MediaType::Audio, $media->mediaType());
        $this->assertNotSame(MediaType::Video, $this->upload(SampleFiles::audios()['flac'][0], 'track.flac')->mediaType());
    }

    // ── What must always be refused ──────────────────────────────────────────

    /**
     * ⚠️ WIDENING AN ALLOW-LIST IS THE MOMENT A HOLE GETS REOPENED. An SVG is an executable
     * document: served inline from our domain, it runs in our users' browsers. The name it is
     * given changes nothing.
     */
    public function test_an_executable_document_disguised_as_a_video_is_still_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'film.mp4');
    }

    public function test_an_html_page_disguised_as_a_video_is_still_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload("<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n", 'clip.webm');
    }

    /**
     * ⚠️ THE LIST IS STILL A LIST. MXF is a perfectly real professional video format — and it is
     * not on it. This test observes that rather than assuming it: that is what distinguishes an
     * allow-list from an open door.
     */
    public function test_a_video_container_outside_the_list_is_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload("\x06\x0e\x2b\x34\x02\x05\x01\x01\x0d\x01\x02\x01\x01\x02\x04\x00".str_repeat("\x00", 20), 'broadcast.mxf');
    }
}
