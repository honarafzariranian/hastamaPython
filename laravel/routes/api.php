<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Everything the Vue client calls.  The URL space mirrors the existing
| application's JSON endpoints, which are catalogued route by route in
| docs/migration/ROUTE_INVENTORY.md (249 routes, extracted from the running
| FastAPI application).  Endpoints are added here as each feature is migrated;
| nothing is stubbed.
|
*/

Route::get('/health', [HealthController::class, 'index'])->name('api.health');
Route::get('/health/database', [HealthController::class, 'database'])->name('api.health.database');
