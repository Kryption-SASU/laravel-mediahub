<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Actions\GenerateConversions;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Contracts\ConversionDrivers;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Http\Resources\MediaResource;
use Kryption\MediaHub\Models\Media;

/**
 * BUILDING A FILE'S DERIVATIVES AGAIN.
 *
 * ⚠️ IT EXISTS BECAUSE DERIVATIVES ARE MADE ONCE, AT UPLOAD. A library that predates the tool
 * that draws its thumbnails has none for anything already in it, and there was no way to ask —
 * short of uploading every file a second time, which would double the storage and change every
 * identifier.
 *
 * ⚠️ AUTHORISED AS A MODIFICATION, NOT AS A READ. It writes files beside somebody's media and
 * replaces what was there; whoever may look at a file is not automatically whoever may change
 * what sits next to it.
 *
 * ⚠️ AND IT IS DONE NOW, NOT QUEUED. The screen that asked shows a spinner on the tile and wants
 * to draw the answer when it lets go — a job dispatched to a worker would have the button do
 * nothing visible, which reads as a broken menu entry and gets clicked four times. The work is
 * bounded: the source has a ceiling and each program has a timeout.
 */
final class ConversionsController
{
    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly ConversionDrivers $drivers,
    ) {
    }

    public function store(Media $media, GenerateConversions $generate): JsonResponse
    {
        if (! $this->policy->modify($media)) {
            throw new AuthorizationException();
        }

        /*
         * ⚠️ REFUSED RATHER THAN ANSWERED WITH NOTHING. "Done, and there is still no picture" is
         * indistinguishable from a failure on screen; a reason says which of the two it is —
         * this machine has no tool for that type, or that type has no picture in it.
         */
        if ($this->drivers->for((string) $media->mime_type) === null) {
            throw OperationRejected::because('conversion_unsupported_here');
        }

        $generate($media);

        return MediaResource::make($media->fresh())->response();
    }
}
