<?php

use Illuminate\Support\Facades\Route;
use Lumina\DesktopConnect\Http\Controllers\DesktopConnectController;
use Lumina\DesktopConnect\Http\Controllers\GrantController;

/*
| Plugin routes are wrapped by PluginServiceProvider with
| middleware web, auth, organization, plugin:desktop-connect,
| prefix x/desktop-connect and name desktop-connect.*.
|
| OGC / GeoPackage / AuthKey land in later DG stories — not here.
*/

Route::get('/', [DesktopConnectController::class, 'index'])->name('index');
Route::get('/ping', [DesktopConnectController::class, 'ping'])->name('ping');

Route::get('/grants', [GrantController::class, 'index'])->name('grants.index');
Route::post('/grants', [GrantController::class, 'store'])->name('grants.store');
Route::delete('/grants/{grant}', [GrantController::class, 'destroy'])->name('grants.destroy');
