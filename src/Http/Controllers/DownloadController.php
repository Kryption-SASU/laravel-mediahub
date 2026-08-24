<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Enums\ConversionState;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Support\RangeStream;

/**
 * SERVING THE FILE — inline, as an attachment, or one of its derivatives.
 *
 * ⚠️ THE MEDIA ARRIVES AS A MODEL, NOT AS A STRING. Implicit binding resolves it through the
 * global scope: an identifier belonging to somebody else does not resolve, and Laravel returns
 * 404 before this controller exists. And if a host forgot `SubstituteBindings`, the typed
 * argument would make the request fail with an error instead of letting it through — a guard
 * that depends on the wiring is not a guard.
 *
 * ⚠️ THREE ROUTES RATHER THAN ONE PARAMETER-SWITCHED ENTRY POINT. `?mode=download` would be one
 * more branch inside a single entry point, and that is exactly the shape that cost the original
 * module twelve holes: one authorisation on the entrance, twelve behaviours behind it.
 */
final class DownloadController
{
    public function __construct(
        private readonly RangeStream $stream,
        private readonly AccessPolicy $policy,
    ) {
    }

    /** The original, displayed inline. */
    public function show(Request $request, Media $media): Response
    {
        $this->authorize($media);

        return $this->stream->respond(
            $request,
            (string) $media->disk,
            (string) $media->path,
            (string) $media->mime_type,
            (string) $media->file_name,
            (int) $media->size,
        );
    }

    /**
     * The original, as an attachment and under the DISPLAYED name.
     *
     * ⚠️ THE DISPLAYED NAME, NOT THE ONE ON DISK. The latter is normalised for storage — no
     * accents, no spaces, sometimes suffixed with an identifier to avoid a collision. Handing
     * it back to the user means giving them "annual-report-2026-a3f9.pdf" where they uploaded
     * "Annual report 2026.pdf".
     */
    public function download(Request $request, Media $media): Response
    {
        $this->authorize($media);

        return $this->stream->respond(
            $request,
            (string) $media->disk,
            (string) $media->path,
            (string) $media->mime_type,
            $this->displayName($media),
            (int) $media->size,
            attachment: true,
        );
    }

    /**
     * A DERIVATIVE.
     *
     * ⚠️ ONLY IF IT IS READY. A pending or failed derivative has no file: serving its row would
     * return an empty stream with a 200 status, that is, a broken image nothing distinguishes
     * from a genuine storage problem.
     */
    public function conversion(Request $request, Media $media, string $conversion): Response
    {
        /*
         * ⚠️ A DERIVATIVE FOLLOWS ITS ORIGINAL'S PERMISSION. A thumbnail is a reduced version
         * of the file, not a separate object: authorising it separately would let a policy
         * forbid the original and serve its preview anyway — on an ID card or a scanned
         * document, the preview is more than enough.
         */
        $this->authorize($media);

        $derivative = $media->conversions()
            ->where('name', $conversion)
            ->where('state', ConversionState::Ready->value)
            ->first();

        if ($derivative === null) {
            throw new NotFoundHttpException();
        }

        return $this->stream->respond(
            $request,
            (string) $derivative->disk,
            (string) $derivative->path,
            (string) $derivative->mime_type,
            basename((string) $derivative->path),
            (int) $derivative->size,
        );
    }

    private function authorize(Media $media): void
    {
        if (! $this->policy->download($media)) {
            throw new AuthorizationException();
        }
    }

    private function displayName(Media $media): string
    {
        $name = trim((string) $media->name);
        $extension = strtolower((string) $media->extension);

        if ($name === '') {
            return (string) $media->file_name;
        }

        if ($extension === '' || str_ends_with(strtolower($name), '.'.$extension)) {
            return $name;
        }

        return $name.'.'.$extension;
    }
}
