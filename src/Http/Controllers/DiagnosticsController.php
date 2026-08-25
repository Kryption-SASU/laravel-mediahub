<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kryption\MediaHub\Actions\DiagnoseSetup;

/**
 * WHAT THIS CONFIGURATION PROMISES, AND WHAT THIS MACHINE WILL DO — read from a screen.
 *
 * ⚠️ THE ROUTE IS NOT REGISTERED WHEN THE FLAG IS OFF, rather than registered and refusing. A
 * door that answers "403" tells anybody asking that there is something behind it; a door that
 * is not there tells them nothing, and the difference costs one line.
 *
 * ⚠️ IT REPORTS, IT DOES NOT REPAIR. Every recommendation names a directive and a value and
 * stops there: nothing here writes to `php.ini` or to the configuration, because a package that
 * quietly raises a limit on its host's server has made a decision that was not its to make.
 */
final class DiagnosticsController
{
    public function __invoke(DiagnoseSetup $diagnose): JsonResponse
    {
        return new JsonResponse(['data' => $diagnose()]);
    }
}
