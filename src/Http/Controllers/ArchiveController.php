<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Kryption\MediaHub\Actions\BuildArchive;
use Kryption\MediaHub\Contracts\AccessPolicy;
use Kryption\MediaHub\Http\Requests\SelectionRequest;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\Support\SelectionResolver;

/**
 * DOWNLOADING SEVERAL THINGS AT ONCE.
 *
 * ⚠️ AS A `POST`, AND THAT IS A CORRECTION. The original module built its URL by hand —
 * `?selected[0][id]=…&selected[1][id]=…` — then opened it in a tab: beyond a few hundred items
 * the front-end server cuts the request off, and the user only sees an unrelated error. The
 * selection therefore travels in the BODY.
 *
 * ⚠️ AND THE BROWSER CAN DOWNLOAD THE RESPONSE TO A `POST`: a hidden form with
 * `target="_blank"` triggers the native save, streamed. ⚠️ WHAT MUST NOT BE DONE is a `fetch()`
 * followed by a `blob()` — that puts the WHOLE archive back into the tab's memory, which is
 * precisely what streaming was there to avoid.
 *
 * ⚠️ EVERY FILE IS AUTHORISED, NOT THE SELECTION AS A BLOCK. An archive is the quietest way to
 * get bytes out: a "per batch" check would let the one file nobody was allowed to take slip
 * through inside a ZIP of two hundred.
 */
final class ArchiveController
{
    public function __construct(
        private readonly SelectionResolver $resolver,
        private readonly AccessPolicy $policy,
        private readonly FolderTree $tree,
    ) {
    }

    public function store(SelectionRequest $request, BuildArchive $build): StreamedResponse
    {
        $items = $this->resolver->resolve($request->selection());

        foreach ($items->media as $media) {
            $this->authorize($media);
        }

        /*
         * ⚠️ THE CONTENT OF THE FOLDERS IS AUTHORISED TOO. A folder is not an object one
         * downloads: it is a list of files, and it is the files that come out. Checking only
         * the folder would turn picking a parent into a bypass.
         */
        foreach ($items->folders as $folder) {
            foreach (Media::query()->whereIn(Media::column('folder_id'), $this->tree->subtreeKeys($folder))->get() as $media) {
                $this->authorize($media);
            }
        }

        /*
         * ⚠️ THE TICKET IS THE PAGE'S OWN AND IT IS PASSED ON AS TEXT OR NOT AT ALL. Whether it
         * is worth anything is decided where it is used, not here — a controller that judged it
         * would be a second opinion on the same question, and the two would eventually differ.
         */
        $ticket = $request->input('ticket');

        return $build(
            $items,
            $request->input('name'),
            is_string($ticket) ? $ticket : null,
        );
    }

    private function authorize(Media $media): void
    {
        if (! $this->policy->download($media)) {
            throw new AuthorizationException();
        }
    }
}
