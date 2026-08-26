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
use Kryption\MediaHub\Jobs\GenerateConversionsJob;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\ExternalTools;

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
 * ⚠️ AND IT IS DONE NOW, NOT QUEUED — WHEREVER IT CAN BE. The screen that asked shows a spinner
 * on the tile and wants to draw the answer when it lets go; a job dispatched to a worker would
 * have the button do nothing visible, which reads as a broken menu entry and gets clicked four
 * times. The work is bounded: the source has a ceiling and each program has a timeout.
 *
 * ⚠️ EXCEPT WHERE THE HOST FORBIDS RUNNING A PROGRAM DURING A REQUEST, and there the choice is
 * not between two ways of answering but between answering and failing. `proc_open` sits in
 * `disable_functions` on most panel-managed hosting, so a thumbnail of a video or of a document
 * cannot be drawn here at all — while the same work succeeds on the queue, whose command line
 * runs under a different configuration. The request then hands the work over and says so with a
 * 202, rather than spending itself on a failure it can predict.
 */
final class ConversionsController
{
    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly ConversionDrivers $drivers,
        private readonly ExternalTools $tools,
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
        $driver = $this->drivers->for((string) $media->mime_type);

        if ($driver === null) {
            throw OperationRejected::because('conversion_unsupported_here');
        }

        /*
         * ⚠️ THE QUESTION IS PUT TO THE DRIVER, NOT TO THE MIME TYPE. "Videos and PDFs need a
         * program" is true of the drivers shipped here and false the moment a host supplies its
         * own — and a caller that guessed would send an image round the queue for nothing, or
         * spend a request on a video it cannot draw.
         */
        if ($driver->needsAProgram() && ! $this->tools->canRunPrograms()) {
            GenerateConversionsJob::dispatch($media);

            /* ⚠️ 202, AND THE DISTINCTION IS THE WHOLE POINT. A 200 would have the screen redraw
             * a tile whose picture does not exist yet, read it as "nothing happened", and invite
             * the same click again. */
            return MediaResource::make($media)->response()->setStatusCode(202);
        }

        $generate($media);

        return MediaResource::make($media->fresh())->response();
    }
}
