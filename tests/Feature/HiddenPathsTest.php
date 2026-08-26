<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Tests\TestCase;

/**
 * PATHS THE HOST HAS DECLARED PRIVATE.
 *
 * ⚠️ AN APPLICATION STORES MORE THAN ITS LIBRARY, and the two share a table. Avatars, the
 * attachments of a private conversation, an image posted in a comment are all media rows and
 * none of them belongs in a file browser. Measured on one estate before this existed: 87
 * attachments from private conversations, 64 avatars and 13 comment images, listed to every
 * back-office user of the organisation and downloadable by identifier.
 */
class HiddenPathsTest extends TestCase
{
    use RefreshDatabase;

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-hidden';
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);

        /*
         * ⚠️ A REAL LOCAL DISK, NOT `Storage::fake()`. The fake disk installs a temporary-URL
         * builder of its own and would have the resources return a URL shape production never
         * uses.
         */
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediahub-hidden',
            'serve' => false,
            'throw' => false,
        ]);

        $app['config']->set('mediahub.library.hidden', [
            '*/users/*',
            '*/chat/attachments/*',
            '*/chat/wallpapers/chat-wallpaper-*',
        ]);
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

    private function media(string $path, string $name = 'File'): Media
    {
        $media = Media::create([
            'disk' => 'media',
            'path' => $path,
            'name' => $name,
            'file_name' => basename($path),
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1024,
            'checksum' => str_repeat('c', 64),
        ]);

        Storage::disk('media')->put($path, 'bytes');

        return $media;
    }

    /** @return array<int, string> */
    private function listed(): array
    {
        return array_column($this->getJson('/media')->assertOk()->json('data.media'), 'id');
    }

    // ── What the library shows ───────────────────────────────────────────────

    public function test_a_declared_path_is_absent_from_the_listing(): void
    {
        $avatar = $this->media('orgs/2/users/portrait.jpg', 'Portrait');
        $ordinary = $this->media('orgs/2/library/report.jpg', 'Report');

        $this->assertSame([$ordinary->uuid], $this->listed());

        /* ⚠️ AND IT IS STILL THERE — the row is concealed, not deleted. A rule that removed
         * anything would be a different feature with a much worse failure mode. */
        $this->assertNotNull(Media::withoutMediaScope()->find($avatar->getKey()));
    }

    /**
     * ⚠️ HIDDEN MEANS HIDDEN, NOT MERELY UNLISTED, and this is the half a listing filter would
     * miss. A file absent from the browser but still returned by its identifier is not private:
     * identifiers are sequential on most schemas, and anybody who has seen one has seen the
     * shape of the others.
     */
    public function test_a_declared_path_is_not_reachable_by_its_identifier(): void
    {
        $attachment = $this->media('orgs/2/chat/attachments/photo.jpg', 'Photo');

        $this->getJson('/media/'.$attachment->uuid)->assertNotFound();
    }

    /**
     * ⚠️ THE COUNTERPART, WITHOUT WHICH THE OTHERS PROVE NOTHING. A scope that concealed
     * everything would satisfy them just as well; what has to be shown is that a path nobody
     * declared is untouched.
     */
    public function test_a_path_nobody_declared_stays_visible(): void
    {
        $ordinary = $this->media('orgs/2/library/report.jpg', 'Report');

        $this->assertSame([$ordinary->uuid], $this->listed());
        $this->getJson('/media/'.$ordinary->uuid)->assertOk();
    }

    // ── The distinction a prefix could not make ──────────────────────────────

    /**
     * ⚠️ THIS IS WHY THE RULE TAKES PATTERNS AND NOT PREFIXES. On the estate this was written
     * for, a person's own chat wallpaper and the one a group shares sit in the SAME folder and
     * are told apart by their name alone. A prefix could only take both or neither — and both is
     * a feature removed, neither a private image published.
     */
    public function test_a_pattern_separates_two_files_of_the_same_folder(): void
    {
        $personal = $this->media('orgs/2/chat/wallpapers/chat-wallpaper-1787.jpg', 'Mine');
        $shared = $this->media('orgs/2/chat/wallpapers/chat-group-wallpaper-1783.jpg', 'Ours');

        $this->assertSame([$shared->uuid], $this->listed());

        $this->getJson('/media/'.$personal->uuid)->assertNotFound();
        $this->getJson('/media/'.$shared->uuid)->assertOk();
    }

    // ── The maintenance door ─────────────────────────────────────────────────

    /**
     * ⚠️ A COMMAND THAT WALKS EVERYTHING HAS TO SEE EVERYTHING, or a rebuild silently skips the
     * files nobody looks at — which is exactly where a broken derivative would go unnoticed
     * longest.
     */
    public function test_the_maintenance_door_still_sees_them(): void
    {
        $this->media('orgs/2/users/portrait.jpg', 'Portrait');
        $this->media('orgs/2/library/report.jpg', 'Report');

        $this->assertSame(2, Media::withoutMediaScope()->count());
        $this->assertSame(1, Media::query()->count());
    }
}
