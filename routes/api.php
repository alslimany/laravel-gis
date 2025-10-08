<?php

use App\Http\Controllers\AttributeTableController;
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
});
