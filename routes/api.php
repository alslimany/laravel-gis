<?php

use App\Http\Controllers\Api\V1\ExportApiController;
use App\Http\Controllers\Api\V1\FeatureApiController;
use App\Http\Controllers\Api\V1\LayerApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Sanctum token clients)
|--------------------------------------------------------------------------
|
| Every /api/v1 route requires a Sanctum ability (DG-0.4). Session (SPA)
| requests receive Sanctum's TransientToken, which passes all ability checks.
|
*/

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Layer segments bind by public_id (or org-scoped alias) — never sequential PK (DG-1.1).
    Route::get('layers', [LayerApiController::class, 'index'])
        ->middleware('abilities:layers:read')
        ->name('api.v1.layers.index');
    Route::get('layers/{layer:public_id}', [LayerApiController::class, 'show'])
        ->middleware('abilities:layers:read')
        ->name('api.v1.layers.show');
    Route::get('layers/{layer:public_id}/geojson', [LayerApiController::class, 'geojson'])
        ->middleware('ability:layers:read,features:read')
        ->name('api.v1.layers.geojson');
    Route::get('layers/{layer:public_id}/export/geojson', [ExportApiController::class, 'geojson'])
        ->middleware('abilities:exports:read')
        ->name('api.v1.layers.export.geojson');
    Route::get('layers/{layer:public_id}/export/csv', [ExportApiController::class, 'csv'])
        ->middleware('abilities:exports:read')
        ->name('api.v1.layers.export.csv');
    Route::get('layers/{layer:public_id}/export/excel', [ExportApiController::class, 'excel'])
        ->middleware('abilities:exports:read')
        ->name('api.v1.layers.export.excel');

    Route::get('layers/{layer:public_id}/features', [FeatureApiController::class, 'index'])
        ->middleware('abilities:features:read')
        ->name('api.v1.features.index');
    Route::post('layers/{layer:public_id}/features', [FeatureApiController::class, 'store'])
        ->middleware('abilities:features:write')
        ->name('api.v1.features.store');
    Route::get('layers/{layer:public_id}/features/{feature}', [FeatureApiController::class, 'show'])
        ->middleware('abilities:features:read')
        ->name('api.v1.features.show');
    Route::put('layers/{layer:public_id}/features/{feature}', [FeatureApiController::class, 'update'])
        ->middleware('abilities:features:write')
        ->name('api.v1.features.update');
    Route::delete('layers/{layer:public_id}/features/{feature}', [FeatureApiController::class, 'destroy'])
        ->middleware('abilities:features:write')
        ->name('api.v1.features.destroy');
});
