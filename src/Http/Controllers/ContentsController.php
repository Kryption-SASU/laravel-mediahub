<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Actions\CountSelection;
use Kryption\MediaHub\Http\Requests\SelectionRequest;
use Kryption\MediaHub\Support\AuthorizeSelection;
use Kryption\MediaHub\Support\SelectionResolver;

/**
 * WHAT A SELECTION CARRIES — the question a confirmation has to answer before it is put.
 *
 * ⚠️ A `POST` FOR A READ, FOR THE SAME REASON THE ARCHIVE ROUTE IS ONE: the selection does not
 * fit in a query string. This changes nothing and is safe to repeat.
 *
 * ⚠️ AND IT IS AUTHORISED LIKE THE DELETION IT PRECEDES. Counting is reading, but reading how
 * much of a library sits under a folder is a fact about that library: answered without the same
 * check, this route would report the size of a branch somebody may not touch, and would report
 * it accurately.
 *
 * ⚠️ TRASHED ITEMS ARE INCLUDED, because the caller asking is about to purge or to restore, and
 * both work on what is already in the trash. A count that quietly left those out would promise
 * a smaller operation than the one that follows.
 */
final class ContentsController
{
    public function __construct(
        private readonly SelectionResolver $resolver,
        private readonly AuthorizeSelection $authorize,
    ) {
    }

    public function __invoke(SelectionRequest $request, CountSelection $count): JsonResponse
    {
        $items = $this->resolver->resolve($request->selection(), withTrashed: true);

        $this->authorize->destroy($items);

        return new JsonResponse(['data' => $count($items, withTrashed: true)]);
    }
}
