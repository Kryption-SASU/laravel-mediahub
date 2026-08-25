<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Actions\UploadMedia;
use Kryption\MediaHub\Contracts\MediaOwner;
use Kryption\MediaHub\Exceptions\QuotaExceeded;
use Kryption\MediaHub\Exceptions\UploadRejected;
use Kryption\MediaHub\Http\Requests\UploadMediaRequest;
use Kryption\MediaHub\Http\Resources\MediaResource;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\FolderLocator;
use Kryption\MediaHub\Support\OwnerContext;
use Kryption\MediaHub\ValueObjects\UploadedPayload;

/**
 * UPLOADING FILES.
 *
 * ⚠️ THE RESULT IS PER FILE, AND THAT IS A DELIBERATE EXCEPTION TO THE BATCH RULE. Elsewhere in
 * this package a batch passes whole or not at all — because it acts on EXISTING objects, and a
 * refusal there is a refusal of RIGHT. Here the right is the same for every file in the upload:
 * it is settled once, beforehand. What can fail per file is the nature of the content, and
 * rejecting twenty photographs because the twenty-first was an executable protects nobody — it
 * makes someone upload twenty photographs again.
 *
 * ⚠️ AND THE STATUS CODE FOLLOWS THE ACTUAL RESULT. A 201 when nothing was created is a lie the
 * client will display as a success.
 *
 * ⚠️ THE FOLDER DOES NOT DECIDE WHERE THE BYTES ARE FILED. It is recorded on the row; the
 * storage path comes from the `PathGenerator`. Tying them together would turn moving a folder
 * into a file migration.
 */
final class UploadController
{
    public function __construct(
        private readonly UploadMedia $upload,
        private readonly FolderLocator $folders,
        private readonly MediaOwner $owner,
    ) {
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        $folder = $this->folders->optional($request->input('folder'));

        /*
         * ⚠️ THE FILE BELONGS TO WHOEVER SENT IT. Nothing here used to say so, and on an adopted
         * schema whose `user_id` is `NOT NULL` that is not a missing fact but a refused insert:
         * uploading through the API did not work at all.
         */
        $context = OwnerContext::for(Media::class, $this->owner);

        if ($folder !== null) {
            $context['folder_id'] = $folder->getKey();
        }

        $uploaded = [];
        $refused = [];

        foreach ($request->file('files', []) as $file) {
            try {
                $uploaded[] = ($this->upload)(UploadedPayload::fromUploadedFile($file), $context);
            } catch (UploadRejected $e) {
                $refused[] = ['file' => $file->getClientOriginalName(), 'reason' => $e->reason];
            } catch (QuotaExceeded) {
                $refused[] = ['file' => $file->getClientOriginalName(), 'reason' => 'quota_exceeded'];
            }
        }

        foreach ($uploaded as $media) {
            $media->load(Media::eagerLoadable());
        }

        return new JsonResponse([
            'data' => MediaResource::collection($uploaded),
            'errors' => $refused,
        ], $uploaded === [] ? 422 : 201);
    }
}
