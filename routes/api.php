<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AttributeTableController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    // Layer API endpoints
    Route::prefix('layers/{layer}')->group(function () {
        Route::get('/geojson', [AttributeTableController::class, 'geojson'])->name('api.layers.geojson');
        Route::get('/attributes', [AttributeTableController::class, 'index'])->name('api.layers.attributes');
        Route::put('/attributes/{featureId}', [AttributeTableController::class, 'update'])->name('api.layers.attributes.update');
    });

    // Map API endpoints
    Route::prefix('maps')->group(function () {
        Route::post('/', [MapController::class, 'store'])->name('api.maps.store');
        Route::put('/{map}', [MapController::class, 'update'])->name('api.maps.update');
    });

    // Analysis API endpoints
    Route::prefix('analysis')->group(function () {
        Route::post('/buffer', [AnalysisController::class, 'buffer'])->name('api.analysis.buffer');
        Route::post('/spatial-query', [AnalysisController::class, 'spatialQuery'])->name('api.analysis.spatial-query');
        Route::post('/attribute-query', [AnalysisController::class, 'attributeQuery'])->name('api.analysis.attribute-query');
        Route::post('/measure-distance', [AnalysisController::class, 'measureDistance'])->name('api.analysis.measure-distance');
        Route::post('/measure-area', [AnalysisController::class, 'measureArea'])->name('api.analysis.measure-area');
        Route::post('/layer-buffer', [AnalysisController::class, 'layerBuffer'])->name('api.analysis.layer-buffer');
    });

    // Export API endpoints
    Route::prefix('export')->group(function () {
        Route::get('/layer/{layer}/geojson', [ExportController::class, 'exportGeoJSON'])->name('api.export.geojson');
        Route::get('/layer/{layer}/csv', [ExportController::class, 'exportCSV'])->name('api.export.csv');
        Route::get('/map/{map}/config', [ExportController::class, 'exportMapConfig'])->name('api.export.map-config');
        Route::get('/map/{map}/prepare', [ExportController::class, 'prepareMapExport'])->name('api.export.map-prepare');
        Route::post('/query-results', [ExportController::class, 'exportQueryResults'])->name('api.export.query-results');
    });
});
