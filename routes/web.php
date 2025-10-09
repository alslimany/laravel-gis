<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AttributeTableController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\LayerController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Health check endpoints (no authentication required)
Route::get('/health', [HealthCheckController::class, 'index'])->name('health.check');
Route::get('/health/detailed', [HealthCheckController::class, 'detailed'])->name('health.detailed');
Route::get('/health/metrics', [HealthCheckController::class, 'metrics'])->name('health.metrics');

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

// Public shared routes
Route::get('/maps/shared/{token}', [MapController::class, 'viewShared'])->name('maps.shared');
Route::get('/projects/shared/{token}', [App\Http\Controllers\ProjectController::class, 'viewShared'])->name('projects.shared');

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

    // Export routes
    Route::get('/export/layer/{layer}/geojson', [ExportController::class, 'exportGeoJSON'])->name('export.layer.geojson');
    Route::get('/export/layer/{layer}/csv', [ExportController::class, 'exportCSV'])->name('export.layer.csv');
    Route::get('/export/map/{map}/config', [ExportController::class, 'exportMapConfig'])->name('export.map.config');

    // Map routes
    Route::get('/maps/builder/{id?}', [MapController::class, 'builder'])->name('maps.builder');
    Route::get('/maps/{map}/share', [MapController::class, 'share'])->name('maps.share');
    Route::resource('maps', MapController::class);

    // Project routes
    Route::get('/projects/{project}/share', [App\Http\Controllers\ProjectController::class, 'share'])->name('projects.share');
    Route::get('/projects/{project}/invite', [App\Http\Controllers\ProjectController::class, 'invite'])->name('projects.invite');
    Route::post('/projects/{project}/invite', [App\Http\Controllers\ProjectController::class, 'storeInvite'])->name('projects.invite.store');
    Route::delete('/projects/{project}/collaborators/{user}', [App\Http\Controllers\ProjectController::class, 'removeCollaborator'])->name('projects.collaborators.remove');
    Route::put('/projects/{project}/collaborators/{user}/role', [App\Http\Controllers\ProjectController::class, 'updateRole'])->name('projects.collaborators.role');
    Route::post('/projects/{project}/comments', [App\Http\Controllers\ProjectController::class, 'storeComment'])->name('projects.comments.store');
    Route::delete('/projects/{project}/comments/{comment}', [App\Http\Controllers\ProjectController::class, 'destroyComment'])->name('projects.comments.destroy');
    Route::resource('projects', App\Http\Controllers\ProjectController::class);

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
