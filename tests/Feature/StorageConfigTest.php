<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kryption\MediaHub\Contracts\DiskResolver;
use Kryption\MediaHub\Exceptions\StorageMisconfigured;
use Kryption\MediaHub\Support\StorageDisk;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * WHERE THE BYTES GO — a disk already configured, or a path given by hand.
 *
 * ⚠️ AND IN BOTH CASES, IT IS A DISK. A path supplied by the host is TURNED INTO a local disk
 * declared on the fly, rather than handled by a second branch throughout the package. This file
 * checks that the transformation does happen — that is, that there are never two code paths for
 * reading, writing or serving a file.
 */
class StorageConfigTest extends TestCase
{
    use RefreshDatabase;

    private function root(): string
    {
        return sys_get_temp_dir().'/mediahub-path';
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->root());

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $storage
     */
    private function resolve(array $storage, string $webRoot = '/var/www/public'): string
    {
        $config = new Repository(['mediahub' => ['storage' => $storage], 'filesystems' => ['disks' => []]]);

        return (new StorageDisk($config))->resolve($webRoot);
    }

    // ── Disk mode ────────────────────────────────────────────────────────────

    public function test_disk_mode_returns_the_named_disk(): void
    {
        $this->assertSame('objects', $this->resolve(['driver' => 'disk', 'disk' => 'objects']));
    }

    public function test_disk_mode_covers_any_disk_the_host_has(): void
    {
        /*
         * ⚠️ THE PACKAGE NAMES NONE IN ITS CODE, including the ones the host adds after
         * installing it. That was the first anomaly found in the original module, which wrote a
         * hardcoded disk name in four places.
         */
        foreach (['s3', 'swift-prod', 'a-disk-invented-tomorrow'] as $name) {
            $this->assertSame($name, $this->resolve(['driver' => 'disk', 'disk' => $name]));
        }
    }

    public function test_a_disk_without_a_name_is_refused(): void
    {
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'disk', 'disk' => '']);
    }

    // ── Path mode ────────────────────────────────────────────────────────────

    public function test_a_path_becomes_a_disk(): void
    {
        $config = new Repository([
            'mediahub' => ['storage' => ['driver' => 'path', 'path' => '/var/data/media', 'name' => 'mediahub']],
            'filesystems' => ['disks' => []],
        ]);

        $name = (new StorageDisk($config))->resolve('/var/www/public');

        $this->assertSame('mediahub', $name);
        $this->assertSame('local', $config->get('filesystems.disks.mediahub.driver'));
        $this->assertSame('/var/data/media', $config->get('filesystems.disks.mediahub.root'));
    }

    public function test_the_built_disk_is_private_and_not_served(): void
    {
        /*
         * ⚠️ THE PATH IS OUTSIDE THE WEB ROOT: it is the package's route that serves the bytes,
         * applying the scope and the policy. Letting Laravel build its own serving URLs would
         * short-circuit both.
         */
        $config = new Repository([
            'mediahub' => ['storage' => ['driver' => 'path', 'path' => '/var/data/media']],
            'filesystems' => ['disks' => []],
        ]);

        (new StorageDisk($config))->resolve('/var/www/public');

        $this->assertSame('private', $config->get('filesystems.disks.mediahub.visibility'));
        $this->assertFalse($config->get('filesystems.disks.mediahub.serve'));
    }

    public function test_a_path_inside_the_web_root_is_refused(): void
    {
        /*
         * ⚠️ THIS REFUSAL IS THE REASON THIS FILE EXISTS. Inside the web root, the front-end
         * server serves the files without going through PHP: the scope, the access policy and
         * the signed URLs become purely decorative, and a guessed identifier is enough to read
         * everything. That is exactly the mistake the original module made with its archives — a
         * ZIP written into `public/`, under a guessable name.
         */
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'path', 'path' => '/var/www/public/uploads']);
    }

    public function test_the_web_root_itself_is_refused(): void
    {
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'path', 'path' => '/var/www/public']);
    }

    public function test_a_folder_next_to_the_web_root_is_still_accepted(): void
    {
        /*
         * ⚠️ THE COUNTER-EXAMPLE, AND IT IS NOT DECORATIVE. A prefix comparison written without
         * the separator would refuse `/var/www/public-media`, which is not inside the web root —
         * and the refusal would be incomprehensible.
         */
        $this->assertSame(
            'mediahub',
            $this->resolve(['driver' => 'path', 'path' => '/var/www/public-media'])
        );
    }

    public function test_a_relative_path_is_refused(): void
    {
        /*
         * ⚠️ THE WEB SERVER, A SCHEDULER COMMAND AND A QUEUE WORKER DO NOT SHARE A CURRENT
         * DIRECTORY. A relative path would scatter the media across three places, two of them
         * invisible from the screen.
         */
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'path', 'path' => 'storage/media']);
    }

    public function test_a_path_that_climbs_is_refused(): void
    {
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'path', 'path' => '/var/data/../www/public']);
    }

    public function test_a_missing_path_is_refused(): void
    {
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'path', 'path' => null]);
    }

    public function test_an_unknown_driver_is_refused_with_no_fallback(): void
    {
        /*
         * ⚠️ NO FALLBACK, AND IT IS THE OPPOSITE OF THE IMAGE DRIVER. That is not an
         * inconsistency: a typo on an image driver costs a thumbnail, a typo on the storage
         * misplaces the originals — and it is discovered while looking for why the backup is
         * empty.
         */
        $this->expectException(StorageMisconfigured::class);

        $this->resolve(['driver' => 'dsik', 'disk' => 'objects']);
    }

    // ── End to end ───────────────────────────────────────────────────────────

    public function test_an_upload_really_lands_in_the_given_path(): void
    {
        /*
         * ⚠️ THE PROOF THAT BOTH MODES SHARE ONE MECHANISM. The upload does not know it was
         * given a path rather than a disk: it writes through the disk, as always.
         */
        $this->app['config']->set('mediahub.storage', [
            'driver' => 'path',
            'path' => $this->root(),
            'name' => 'mediahub',
        ]);

        $this->app['files']->deleteDirectory($this->root());
        $this->app['files']->ensureDirectoryExists($this->root());

        $this->assertSame('mediahub', $this->app->make(DiskResolver::class)->forUpload([]));

        $source = tempnam(sys_get_temp_dir(), 'mh');
        file_put_contents($source, 'some bytes');

        $media = $this->app->make(\Kryption\MediaHub\Actions\UploadMedia::class)(
            UploadedPayload::fromLocalFile($source, 'note.txt')
        );

        $this->assertSame('mediahub', $media->disk);
        $this->assertFileExists($this->root().'/'.$media->path);
    }
}
