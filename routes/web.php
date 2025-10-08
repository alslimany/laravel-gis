<?php

use App\Http\Controllers\AttributeTableController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\LayerController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

// Protected routes - require authentication
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Data Import routes
    Route::resource('imports', DataImportController::class)->except(['edit', 'update']);
    Route::get('/imports/{import}/status', [DataImportController::class, 'status'])->name('imports.status');

    // Layer routes
    Route::resource('layers', LayerController::class);
    Route::post('/layers/{layer}/publish', [LayerController::class, 'publish'])->name('layers.publish');
    Route::post('/layers/{layer}/unpublish', [LayerController::class, 'unpublish'])->name('layers.unpublish');
    Route::post('/layers/{layer}/style', [LayerController::class, 'updateStyle'])->name('layers.style.update');
    
    // Attribute table routes
    Route::get('/layers/{layer}/attributes', [AttributeTableController::class, 'index'])->name('layers.attributes');
    Route::get('/layers/{layer}/geojson', [AttributeTableController::class, 'geojson'])->name('layers.geojson');

    // Organization routes - require organization membership
    Route::middleware('organization')->group(function () {
        Route::get('/organization/settings', [OrganizationController::class, 'settings'])->name('organization.settings');
        Route::put('/organization', [OrganizationController::class, 'update'])->name('organization.update');
    });

    // Admin routes - require admin role
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'edit', 'update', 'destroy']);
    });
});
