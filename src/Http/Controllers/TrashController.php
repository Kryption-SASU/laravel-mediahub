<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Actions\EmptyTrash;
use Kryption\MediaHub\Actions\ForceDeleteItems;
use Kryption\MediaHub\Actions\RestoreItems;
use Kryption\MediaHub\Actions\TrashItems;
use Kryption\MediaHub\Http\Requests\SelectionRequest;
use Kryption\MediaHub\Support\AuthorizeSelection;
use Kryption\MediaHub\Support\SelectionResolver;

/**
 * THE TRASH — putting things in, taking them out, emptying it, purging from it.
 *
 * ⚠️ FOUR ROUTES, NOT ONE WITH AN `action` FIELD. This is the most expensive lesson from the
 * module this package replaces: a single catch-all endpoint switched twelve behaviours on a
 * field of the request body, behind a single authorisation — and eight of them were
 * destructive. Here, trashing and deleting for good share neither a URL nor a verb.
 *
 * ⚠️ AND THE ORDER IS ALWAYS THE SAME: resolve, authorise EVERYTHING, then act. Resolving goes
 * through the scope; authorising goes over each item; acting only comes afterwards. Any other
 * sequence leaves a batch half done.
 */
final class TrashController
{
    public function __construct(
        private readonly SelectionResolver $resolver,
        private readonly AuthorizeSelection $authorize,
    ) {
    }

    /** Put in the trash. */
    public function store(SelectionRequest $request, TrashItems $trash): JsonResponse
    {
        $items = $this->resolver->resolve($request->selection());

        $this->authorize->destroy($items);

        return new JsonResponse(['data' => ['count' => $trash($items)->count()]]);
    }

    /** Take back out. */
    public function restore(SelectionRequest $request, RestoreItems $restore): JsonResponse
    {
        $items = $this->resolver->resolve($request->selection(), withTrashed: true);

        $this->authorize->destroy($items);

        return new JsonResponse(['data' => ['count' => $restore($items)->count()]]);
    }

    /** Delete a selection for good. */
    public function purge(SelectionRequest $request, ForceDeleteItems $purge): JsonResponse
    {
        $items = $this->resolver->resolve($request->selection(), withTrashed: true);

        $this->authorize->destroy($items);

        return new JsonResponse(['data' => ['count' => $purge($items)->count()]]);
    }

    /**
     * EMPTYING THE TRASH.
     *
     * ⚠️ THIS ONE TAKES NO SELECTION, which is why it has nothing to authorise item by item: it
     * covers everything the scope shows as deleted. A policy wanting to restrict it does so by
     * refusing the route, not by filtering its content — a partial emptying would be invisible
     * and incomprehensible.
     */
    public function destroy(EmptyTrash $empty): JsonResponse
    {
        return new JsonResponse(['data' => ['count' => $empty()->count()]]);
    }
}
