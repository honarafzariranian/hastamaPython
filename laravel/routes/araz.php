<?php

use App\Http\Controllers\Araz\ArazBridgeSyncController;
use App\Http\Controllers\Araz\ArazConfigController;
use App\Http\Controllers\Araz\ArazDeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Araz T7 device bridge and configuration. Ported from app/api/routes/araz_api.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes.web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| The device and configuration endpoints carry the standard `admin` guard.
| `bridge-sync` is the machine-to-machine exception: it authenticates by
| comparing the request's `secret` against `ARAZ_BRIDGE_SECRET` and carries
| no session middleware at all.  It is CSRF-exempt through the shared
| `/api/araz/` prefix in config/hastama.php (which this file must not edit).
*/

Route::prefix('api/araz')->group(function (): void {
    Route::get('/config', [ArazConfigController::class, 'show'])
        ->name('araz.config.show');
    Route::post('/config', [ArazConfigController::class, 'update'])
        ->name('araz.config.update');

    Route::middleware(['admin', 'legacy.session:optional'])->group(function (): void {
        Route::get('/test', [ArazDeviceController::class, 'test'])
            ->name('araz.test');
        Route::get('/time', [ArazDeviceController::class, 'time'])
            ->name('araz.time');
        Route::post('/time/sync', [ArazDeviceController::class, 'timeSync'])
            ->name('araz.time.sync');
        Route::get('/records', [ArazDeviceController::class, 'records'])
            ->name('araz.records');
        Route::post('/sync', [ArazDeviceController::class, 'sync'])
            ->name('araz.sync');
    });

    Route::post('/bridge-sync', [ArazBridgeSyncController::class, 'sync'])
        ->name('araz.bridge-sync');
});
