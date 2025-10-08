<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
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
