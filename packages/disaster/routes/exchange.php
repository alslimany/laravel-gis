<?php

use Illuminate\Support\Facades\Route;
use Lumina\Disaster\Http\Controllers\Api\Exchange\HaveExchangeController;
use Lumina\Disaster\Http\Controllers\Api\Exchange\RmExchangeController;
use Lumina\Disaster\Http\Controllers\Api\Exchange\SitRepExchangeController;
use Lumina\Disaster\Http\Middleware\AuthenticateExchangeToken;

/*
| Partner EDXL exchange API (Bearer dm_exchange_tokens).
| Prefix: /x/disaster/api/v1/exchange
*/

Route::middleware([
    'throttle:60,1',
    AuthenticateExchangeToken::class.':have.export',
])->get('have', [HaveExchangeController::class, 'export'])->name('have.export');

Route::middleware([
    'throttle:60,1',
    AuthenticateExchangeToken::class.':have.import',
])->post('have', [HaveExchangeController::class, 'import'])->name('have.import');

Route::middleware([
    'throttle:60,1',
    AuthenticateExchangeToken::class.':rm.export',
])->get('rm', [RmExchangeController::class, 'export'])->name('rm.export');

Route::middleware([
    'throttle:60,1',
    AuthenticateExchangeToken::class.':rm.import',
])->post('rm', [RmExchangeController::class, 'import'])->name('rm.import');

Route::middleware([
    'throttle:60,1',
    AuthenticateExchangeToken::class.':sitrep.export',
])->get('sitrep', [SitRepExchangeController::class, 'export'])->name('sitrep.export');

Route::middleware([
    'throttle:60,1',
    AuthenticateExchangeToken::class.':sitrep.import',
])->post('sitrep', [SitRepExchangeController::class, 'import'])->name('sitrep.import');
