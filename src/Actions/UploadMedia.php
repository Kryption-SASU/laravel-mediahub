<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kryption\MediaHub\Contracts\DiskResolver;
use Kryption\MediaHub\Contracts\DuplicateResolver;
use Kryption\MediaHub\Contracts\FileNamer;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Contracts\MediaTypeResolver;
use Kryption\MediaHub\Contracts\PathGenerator;
use Kryption\MediaHub\Contracts\QuotaPolicy;
use Kryption\MediaHub\Contracts\UploadValidator;
use Kryption\MediaHub\Enums\DuplicateDecision;
use Kryption\MediaHub\Events\MediaUploaded;
use Kryption\MediaHub\Exceptions\QuotaExceeded;
use Kryption\MediaHub\Exceptions\UploadRejected;
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\DeepUploadValidator;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * UPLOADING A MEDIA — one operation, one class, one test.
 *
 * ⚠️ THE ORDER IS WHAT THIS CLASS IS ABOUT: validate, check the quota, write the bytes, THEN
 * record the row. Every inversion costs something specific —
 *
 *   - writing before validating leaves on the storage what has just been refused;
 *   - recording before writing produces a row naming a file that is not there, that is, a dead
 *     image nothing reports;
 *   - checking the quota after the write checks it too late.
 *
 * ⚠️ AND THE LEAST BAD FAILURE IS CHOSEN: if recording fails after the write, the bytes are
 * removed. If an object without a row survives anyway, it is an orphan nobody sees — far
 * preferable to a row without an object, which everybody sees.
 */
final class UploadMedia
{
    public function __construct(
        private readonly MediaScope $scope,
        private readonly DiskResolver $disks,
        private readonly PathGenerator $paths,
        private readonly FileNamer $namer,
        private readonly UploadValidator $validator,
        private readonly QuotaPolicy $quota,
        private readonly MediaTypeResolver $types,
        private readonly DuplicateResolver $duplicates,
        private readonly FilesystemFactory $filesystems,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context  what the caller knows: folder, owner, scope
     */
    public function __invoke(UploadedPayload $payload, array $context = []): Media
    {
        $this->validator->validate($payload, $context);

        $path = (string) $payload->localPath;
        $size = (int) ($payload->size ?? filesize($path));
        $scope = $context['scope'] ?? $this->scope->currentKey();

        /* ⚠️ BEFORE THE WRITE, NEVER AFTER. */
        if (! $this->quota->allows($scope, $size)) {
            throw new QuotaExceeded($scope, $size);
        }

        $checksum = $this->checksum($path);
        $existing = $this->existing($checksum, $scope);

        if ($existing !== null) {
            /*
             * ⚠️ REUSING IS NOT DOING NOTHING. No byte was written, but an upload did take
             * place: a listener that counts, logs or notifies must see it go by. The flag says
             * which of the two cases it was — without it, the listener would believe in a new
             * file and count it twice towards usage.
             */
            $this->events->dispatch(new MediaUploaded($existing, reused: true));

            return $existing;
        }

        $disk = $this->disks->forUpload($context);
        $directory = $this->paths->directory($context);

        $name = $this->namer->unique(
            $this->namer->sanitize($payload->originalName).$this->extension($payload),
            $disk,
            $directory
        );

        $target = $directory.$name;

        $this->write($disk, $target, $path);

        try {
            $media = $this->record($payload, $context, $disk, $target, $name, $size, $checksum, $scope);
        } catch (\Throwable $e) {
            /* The row could not be born: we do not leave its bytes behind. */
            $this->filesystems->disk($disk)->delete($target);

            throw $e;
        }

        /*
         * ⚠️ AFTER RECORDING, AND OUTSIDE THE REQUEST. Resizing is counted in seconds: doing it
         * here would make the person uploading wait, and a multiple upload would multiply that
         * wait until it timed out — for an accessory whose absence prevents nothing.
         *
         * ⚠️ AND THE ORIGINAL IS ALREADY WRITTEN, INTACT. Derivatives are EXTRA files: a video,
         * a document, an archive produce none and are not touched.
         */
        /*
         * ⚠️ WHAT TO BUILD CAN COME FROM THE CALLER, and `null` still means "whatever the
         * configuration says". A collection decides at the moment the file is attached; reading
         * it again on the worker would mean asking a model that may have changed since.
         */
        $wanted = $context['conversions'] ?? null;

        GenerateConversionsJob::dispatch($media, is_array($wanted) ? $wanted : null);

        $this->events->dispatch(new MediaUploaded($media));

        return $media;
    }

    /**
     * ⚠️ A DUPLICATE IS JUDGED ON THE CONTENT CHECKSUM, NOT ON THE NAME. Two files with the
     * same name may differ, and two differently named files be identical.
     *
     * ⚠️ AND THE SEARCH IS BOUNDED TO THE SCOPE. Reusing another customer's object would make
     * their file depend on us keeping ours — and would show it to them.
     */
    private function existing(string $checksum, ?string $scope): ?Media
    {
        /*
         * ⚠️ WITHOUT A CHECKSUM COLUMN THERE IS NO DUPLICATE TO RECOGNISE — and that is a fact,
         * not a failure. The schema serving as the target has none: querying `checksum` there
         * would produce an SQL error on every upload. So we always upload, which is the host's
         * behaviour today.
         */
        if (! Media::hasColumn('checksum')) {
            return null;
        }

        if ($this->duplicates->resolve($checksum, $scope) !== DuplicateDecision::Reuse) {
            return null;
        }

        return Media::query()->where(Media::column('checksum'), $checksum)->first();
    }

    /** The extension comes from the declared name, already confronted with the content by validation. */
    private function extension(UploadedPayload $payload): string
    {
        $extension = $payload->declaredExtension();

        return $extension === '' ? '' : '.'.$extension;
    }

    /**
     * ⚠️ STREAMED, NEVER IN MEMORY. With a ceiling counted in gigabytes, reading the whole file
     * before writing it is the difference between "it works" and "it kills the process".
     */
    private function write(string $disk, string $target, string $source): void
    {
        $handle = @fopen($source, 'rb');

        if ($handle === false) {
            throw UploadRejected::because('unreadable');
        }

        try {
            $this->filesystems->disk($disk)->writeStream($target, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(
        UploadedPayload $payload,
        array $context,
        string $disk,
        string $target,
        string $name,
        int $size,
        string $checksum,
        ?string $scope
    ): Media {
        $realType = $this->realMimeType((string) $payload->localPath);

        $attributes = [
            'disk' => $disk,
            'path' => $target,
            'name' => pathinfo($payload->originalName, PATHINFO_FILENAME),
            'file_name' => $name,
            'extension' => $payload->declaredExtension() ?: null,
            'mime_type' => $realType,
            'type' => $this->types->resolve($realType, $payload->declaredExtension())->value,
            'size' => $size,
            'checksum' => $checksum,
        ];

        if ($scope !== null) {
            $attributes['scope_key'] = $scope;
        }

        foreach (['folder_id', 'owner_type', 'owner_id', 'visibility'] as $field) {
            if (array_key_exists($field, $context)) {
                $attributes[$field] = $context[$field];
            }
        }

        if (str_starts_with($realType, 'image/')) {
            $dimensions = @getimagesize((string) $payload->localPath);

            if ($dimensions !== false) {
                $attributes['width'] = $dimensions[0];
                $attributes['height'] = $dimensions[1];
            }
        }

        return Media::create($attributes);
    }

    private function realMimeType(string $path): string
    {
        if ($this->validator instanceof DeepUploadValidator) {
            return $this->validator->realMimeType($path);
        }

        $info = @finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            return 'application/octet-stream';
        }

        $type = @finfo_file($info, $path);
        finfo_close($info);

        return is_string($type) && $type !== '' ? strtolower($type) : 'application/octet-stream';
    }

    private function checksum(string $path): string
    {
        $checksum = @hash_file('sha256', $path);

        if ($checksum === false) {
            throw UploadRejected::because('unreadable');
        }

        return $checksum;
    }
}
