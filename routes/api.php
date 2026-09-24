<?php

use App\Http\Controllers\Api\V1\ExportApiController;
use App\Http\Controllers\Api\V1\FeatureApiController;
use App\Http\Controllers\Api\V1\LayerApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Sanctum token clients)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('layers', [LayerApiController::class, 'index'])->name('api.v1.layers.index');
    Route::get('layers/{layer}', [LayerApiController::class, 'show'])->name('api.v1.layers.show');
    Route::get('layers/{layer}/geojson', [LayerApiController::class, 'geojson'])->name('api.v1.layers.geojson');
    Route::get('layers/{layer}/export/geojson', [ExportApiController::class, 'geojson'])->name('api.v1.layers.export.geojson');
    Route::get('layers/{layer}/export/csv', [ExportApiController::class, 'csv'])->name('api.v1.layers.export.csv');
    Route::get('layers/{layer}/export/excel', [ExportApiController::class, 'excel'])->name('api.v1.layers.export.excel');

    Route::get('layers/{layer}/features', [FeatureApiController::class, 'index'])->name('api.v1.features.index');
    Route::post('layers/{layer}/features', [FeatureApiController::class, 'store'])->name('api.v1.features.store');
    Route::get('layers/{layer}/features/{feature}', [FeatureApiController::class, 'show'])->name('api.v1.features.show');
    Route::put('layers/{layer}/features/{feature}', [FeatureApiController::class, 'update'])->name('api.v1.features.update');
    Route::delete('layers/{layer}/features/{feature}', [FeatureApiController::class, 'destroy'])->name('api.v1.features.destroy');
});
