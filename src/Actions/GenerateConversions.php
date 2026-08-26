<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Config\Repository as Config;
use Kryption\MediaHub\Contracts\ConversionDriver;
use Kryption\MediaHub\Contracts\ConversionDrivers;
use Kryption\MediaHub\Contracts\PathGenerator;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Exceptions\NothingToDraw;
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
        private readonly ConversionDrivers $drivers,
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

        /*
         * ⚠️ WHICH DRIVER ANSWERS FOR THIS TYPE, ASKED ONCE AND CARRIED. An image, a video and a
         * document are drawn by three different things, and only one of them is the library the
         * host configured. Asking again inside the loop would let a machine that lost a tool
         * between two definitions produce a set of derivatives that disagree with each other.
         */
        $driver = $this->drivers->for($source);

        if ($driver === null) {
            return [];
        }

        $definitions ??= (array) $this->config->get('mediahub.conversions.definitions', []);

        $produced = [];

        foreach ($definitions as $name => $definition) {
            $made = $this->produce($driver, $media, (string) $name, (array) $definition, $source);

            /* ⚠️ A DEFINITION THAT HAD NOTHING TO DRAW ADDS NOTHING to what was produced. */
            if ($made !== null) {
                $produced[] = $made;
            }
        }

        return $produced;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function produce(
        ConversionDriver $driver,
        Media $media,
        string $name,
        array $definition,
        string $source,
    ): ?MediaConversion {
        $output = $driver->outputMimeType($source);

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
            $result = $driver->convert((string) $media->disk, (string) $media->path, $target, $definition);

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
        } catch (NothingToDraw) {
            /*
             * ⚠️ NOT EVERY VIDEO TYPE HOLDS A PICTURE, and a "failed" row would claim a fault
             * where there was no work. The `.wma` is the case that settles it: ASF is one
             * container for both, so `finfo` answers `video/x-ms-asf` for a purely audio file.
             *
             * ⚠️ THE PENDING ROW IS REMOVED RATHER THAN LEFT, because it was created before
             * anybody could know — and a row stuck at "pending" for ever is the same lie as a
             * failure, told more slowly.
             */
            $row->delete();
            $this->mirror->forget($media, $name);

            return null;
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
