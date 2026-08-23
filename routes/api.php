<?php

use App\Http\Controllers\Api\PrintQueueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
| Deliberately small. The PWA/offline endpoints live in web.php because they
| authenticate with the session cookie, not a bearer token — see NOTES.md.
|
| These routes serve the local print agent, which polls for jobs.
*/

Route::get('/user', fn (Illuminate\Http\Request $request) => $request->user())
    ->middleware('auth:sanctum');

Route::prefix('print-queue')->name('api.print-queue.')->group(function () {
    Route::get('/pending', [PrintQueueController::class, 'pending'])->name('pending');
    Route::post('/{id}/complete', [PrintQueueController::class, 'complete'])->name('complete');
    Route::post('/cleanup', [PrintQueueController::class, 'cleanup'])->name('cleanup');
});
