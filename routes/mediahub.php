<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kryption\MediaHub\Http\Controllers\ArchiveController;
use Kryption\MediaHub\Http\Controllers\BrowseController;
use Kryption\MediaHub\Http\Controllers\ContentsController;
use Kryption\MediaHub\Http\Controllers\DownloadController;
use Kryption\MediaHub\Http\Controllers\FolderController;
use Kryption\MediaHub\Http\Controllers\MediaController;
use Kryption\MediaHub\Http\Controllers\QuotaController;
use Kryption\MediaHub\Http\Controllers\TrashController;
use Kryption\MediaHub\Http\Controllers\UploadController;
use Kryption\MediaHub\Http\Middleware\ValidateSignatureWhenPresent;

/*
 * THE PACKAGE'S ROUTES.
 *
 * ⚠️ ONE ROUTE PER OPERATION. The module this package replaces exposed a single catch-all
 * endpoint, switching twelve behaviours on a field of the request body: one authorisation to
 * forget in order to open twelve holes — and that is exactly what happened, on eight
 * destructive branches. Here, trashing and deleting for good share neither a URL nor a verb,
 * and that can be read in this file.
 *
 * ⚠️ THIS FILE IS LOADED TWICE AT MOST: once in the web group, once in the API group, each with
 * its own name prefix. Route names must therefore never be written absolutely here — it is the
 * group that prefixes them.
 *
 * ⚠️ AND THE PARAMETER IS CALLED `media`, NOT ANYTHING ELSE. Implicit binding matches the route
 * parameter to the name of the controller argument: renaming it would break resolution
 * silently, and the controller would receive a string where it expects a model.
 */

/*
 * ⚠️ FIXED PATHS FIRST, PARAMETERISED ONES AFTER — the order decides. Laravel takes the FIRST
 * matching route: declared after `{media}`, the `quota` URL would be read as a media
 * identifier, would resolve to nothing, and would return a 404 nothing explains.
 */
Route::get('/', [BrowseController::class, 'index'])->name('index');
Route::get('quota', [QuotaController::class, 'show'])->name('quota');

Route::post('/', [UploadController::class, 'store'])->name('upload');

Route::post('folders', [FolderController::class, 'store'])->name('folders.store');
Route::patch('folders/{folder}', [FolderController::class, 'update'])->name('folders.update');

/*
 * ⚠️ A `POST` FOR A DOWNLOAD, AND IT IS DELIBERATE. The selection does not fit in a query
 * string: the original built it by hand, `selected[0][id]=…`, and the front-end server cut it
 * off beyond a few hundred items.
 */
Route::post('archive', [ArchiveController::class, 'store'])->name('archive');

/*
 * ⚠️ A `POST` FOR A READ, for the same reason as the archive above: the selection does not
 * fit in a query string. It answers what a selection actually carries — a folder's subtree
 * included — so a confirmation can name it before anything is destroyed.
 */
Route::post('contents', ContentsController::class)->name('contents');

Route::post('trash', [TrashController::class, 'store'])->name('trash.store');
Route::post('trash/restore', [TrashController::class, 'restore'])->name('trash.restore');
Route::post('trash/purge', [TrashController::class, 'purge'])->name('trash.purge');
Route::delete('trash', [TrashController::class, 'destroy'])->name('trash.empty');

Route::middleware(ValidateSignatureWhenPresent::class)->group(static function (): void {
    Route::get('{media}/file', [DownloadController::class, 'show'])->name('file');
    Route::get('{media}/download', [DownloadController::class, 'download'])->name('download');
    Route::get('{media}/conversions/{conversion}', [DownloadController::class, 'conversion'])
        ->name('conversion');
});

Route::post('{media}/copy', [MediaController::class, 'copy'])->name('copy');

Route::get('{media}', [MediaController::class, 'show'])->name('show');
Route::patch('{media}', [MediaController::class, 'update'])->name('update');
