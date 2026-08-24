<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Contracts\QuotaPolicy;

/**
 * HOW MUCH ROOM, AND HOW MUCH IS TAKEN.
 *
 * ⚠️ THE MEASUREMENT APPLIES TO THE SCOPE, NOT TO THE PERSON. The original module added a
 * column to its host's users table, then measured per PERSON in a product that partitions per
 * ORGANISATION: the two figures were not talking about the same object, and neither of them was
 * the one anyone thought they were reading.
 *
 * ⚠️ AND `null` MEANS UNLIMITED, NOT ZERO. Returning `0` would display "full" to a product that
 * never wanted a quota — which is the package's default.
 */
final class QuotaController
{
    public function __construct(
        private readonly QuotaPolicy $quota,
        private readonly MediaScope $scope,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $scope = $this->scope->currentKey();

        $limit = $this->quota->limitInBytes($scope);
        $used = $this->quota->usedInBytes($scope);

        return new JsonResponse([
            'data' => [
                'limit' => $limit,
                'used' => $used,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'unlimited' => $limit === null,
            ],
        ]);
    }
}
