<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Config\Repository as Config;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Contracts\PathGenerator;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Backends\LegacyConversionMirror;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaConversion;

/**
 * BUILDING THE DERIVATIVES — and above all, knowing when there are none to build.
 *
 * ⚠️ THE ORIGINAL IS NEVER TOUCHED. Not resized, not re-encoded, not recompressed: the bytes
 * uploaded are the bytes served. A media library that "optimises" what it is entrusted with
 * destroys without saying so, and that is irreversible. Derivatives are EXTRA files.
 *
 * ⚠️ A VIDEO PRODUCES NO ROW — not even a "failed" one. This is not an implementation detail: a
 * failed row would display an error state for something that was never attempted, and would
 * push someone into investigating a failure that does not exist. Nothing failed: there was
 * nothing to do.
 *
 * ⚠️ AND THE DECISION IS MADE ON `supports()`, NOT ON AN "IS IT AN IMAGE" TEST. The question is
 * not the nature of the file but what the machine can do with it: a TIFF is an image GD cannot
 * read, a PDF is not one and Imagick can draw a thumbnail from it. The original module decided
 * with `is_image($mimeType)` and thereby shut the door on PDF while promising TIFF.
 */
final class GenerateConversions
{
    /**
     * THE PRODUCED TYPE → THE EXTENSION THAT DESCRIBES IT.
     *
     * ⚠️ A DERIVATIVE'S EXTENSION MUST DESCRIBE ITS CONTENT. The thumbnail of a PDF is an
     * image: calling it `report-thumb.pdf` would have it served with the wrong type by every
     * host that deduces one from the other — and there are many.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tiff',
    ];

    public function __construct(
        private readonly ConversionDriver $driver,
        private readonly PathGenerator $paths,
        private readonly Config $config,
        private readonly LegacyConversionMirror $mirror,
    ) {
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $definitions
     * @return array<int, MediaConversion>
     */
    public function __invoke(Media $media, ?array $definitions = null): array
    {
        /*
         * ⚠️ WITH NO CONVERSIONS TABLE, WE BUILD NONE — and that is a fact of the schema, not a
         * failure. Building them without being able to record them would produce files nothing
         * references any more: orphans, exactly what the package spends its time avoiding.
         */
        if (! HostSchema::hasTable('conversions')) {
            return [];
        }

        $source = (string) $media->mime_type;

        if (! $this->driver->supports($source)) {
            return [];
        }

        $definitions ??= (array) $this->config->get('mediahub.conversions.definitions', []);

        $produced = [];

        foreach ($definitions as $name => $definition) {
            $produced[] = $this->produce($media, (string) $name, (array) $definition, $source);
        }

        return $produced;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function produce(Media $media, string $name, array $definition, string $source): MediaConversion
    {
        $output = $this->driver->outputMimeType($source);

        $target = $this->paths->conversion(
            (string) $media->path,
            $name,
            self::EXTENSIONS[$output] ?? null
        );

        /*
         * ⚠️ THE ROW IS BORN "PENDING" BEFORE THE WORK. That is what lets a screen show a
         * placeholder while it happens, rather than a broken image — and what makes a build
         * that never comes back visible.
         */
        $row = MediaConversion::query()->updateOrCreate(
            ['media_id' => $media->getKey(), 'name' => $name],
            [
                'disk' => (string) $media->disk,
                'path' => $target,
                'state' => ConversionState::Pending,
                'error' => null,
            ]
        );

        try {
            $result = $this->driver->convert((string) $media->disk, (string) $media->path, $target, $definition);

            $row->forceFill([
                'mime_type' => $result['mime_type'] ?? $output,
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null,
                'size' => $result['size'] ?? 0,
                'state' => ConversionState::Ready,
            ])->save();

            /*
             * ⚠️ THE MIRROR IS ONLY WRITTEN ONCE THE DERIVATIVE IS READY. Setting it when the
             * row is created would show the old screen a thumbnail that does not exist yet —
             * and, if the build fails, a permanently broken image, since that screen has no
             * state to tell "pending" from "failed".
             */
            $this->mirror->reflect($media, $name, $target);
        } catch (\Throwable $e) {
            /*
             * ⚠️ THE FAILURE IS RECORDED, NOT PROPAGATED. The file is uploaded and served:
             * failing a successful upload because a thumbnail could not be produced would mean
             * losing the file over an accessory.
             *
             * ⚠️ BUT IT IS RECORDED. Catching without leaving a trace would give a library
             * where thumbnails are missing and nobody can say why.
             */
            $row->forceFill([
                'state' => ConversionState::Failed,
                'error' => $e->getMessage(),
            ])->save();

            /*
             * ⚠️ AND THE MIRROR GOES AWAY. A REBUILD that fails would otherwise leave the old
             * screen pointing at the previous run's file — a file the failed attempt may have
             * half overwritten. Better no thumbnail at all than one nobody can be sure belongs
             * to the right file.
             */
            $this->mirror->forget($media, $name);
        }

        return $row;
    }
}
