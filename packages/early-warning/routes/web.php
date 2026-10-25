<?php

use Illuminate\Support\Facades\Route;
use Lumina\EarlyWarning\Http\Controllers\AlertRulesController;
use Lumina\EarlyWarning\Http\Controllers\CitizenReportsController;
use Lumina\EarlyWarning\Http\Controllers\EarlyWarningController;
use Lumina\EarlyWarning\Http\Controllers\WarningsController;

/*
| Plugin routes are wrapped by PluginServiceProvider with
| middleware web, auth, organization, plugin:early-warning,
| prefix x/early-warning and name early-warning.*.
*/

Route::get('/', [EarlyWarningController::class, 'index'])->name('index');
Route::get('/health', [EarlyWarningController::class, 'health'])->name('health');
Route::get('/warnings', WarningsController::class)->name('warnings');

Route::get('/alert-rules', [AlertRulesController::class, 'index'])->name('alert-rules.index');
Route::post('/alert-rules', [AlertRulesController::class, 'store'])->name('alert-rules.store');
Route::post('/alert-rules/seed', [AlertRulesController::class, 'seed'])->name('alert-rules.seed');
Route::put('/alert-rules/channels', [AlertRulesController::class, 'updateChannels'])->name('alert-rules.channels');
Route::put('/alert-rules/{alertRule}', [AlertRulesController::class, 'update'])->name('alert-rules.update');
Route::delete('/alert-rules/{alertRule}', [AlertRulesController::class, 'destroy'])->name('alert-rules.destroy');

Route::get('/citizen-reports', [CitizenReportsController::class, 'index'])->name('citizen-reports.index');
Route::put('/citizen-reports/{citizenReport}', [CitizenReportsController::class, 'update'])->name('citizen-reports.update');
Route::post('/public-tokens', [CitizenReportsController::class, 'issueToken'])->name('public-tokens.store');
Route::delete('/public-tokens/{publicToken}', [CitizenReportsController::class, 'revokeToken'])->name('public-tokens.destroy');
