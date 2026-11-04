<?php

use Illuminate\Support\Facades\Route;
use Lumina\DesktopConnect\Http\Controllers\DesktopLayerApiController;
use Lumina\DesktopConnect\Http\Middleware\EnsureDesktopLayerAbility;

/*
| Desktop JSON API (DG-1.6). Mounted by DesktopConnectServiceProvider with
| auth:sanctum, organization, plugin:desktop-connect.
|
| Layer segment binds by public_id (or org-scoped alias) — never sequential PK.
*/

Route::get('/layers/{layer:public_id}', [DesktopLayerApiController::class, 'show'])
    ->middleware([EnsureDesktopLayerAbility::class.':read'])
    ->name('layers.show');
