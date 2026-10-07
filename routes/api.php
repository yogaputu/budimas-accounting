<?php

use App\Http\Controllers\Api\Accounting\PublicHealthController;
use App\Http\Controllers\Api\Accounting\StagingBatchController;
use App\Http\Controllers\Api\Accounting\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/accounting')->group(function (): void {
    Route::get('/health', PublicHealthController::class)
        ->name('accounting.health');

    Route::get('/status', SystemStatusController::class)
        ->middleware(['auth', 'active', 'permission:system.health.view'])
        ->name('accounting.system-status');

    Route::post('/staging/batches', StagingBatchController::class)
        ->middleware(['throttle:30,1', 'staging.ingest'])
        ->name('accounting.staging.batches.store');
});
