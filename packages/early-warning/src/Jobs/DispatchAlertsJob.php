<?php

namespace Lumina\EarlyWarning\Jobs;

use App\Plugins\Contracts\GlobalJob;
use App\Plugins\PluginManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Alerts\AlertDispatcher;
use Throwable;

/**
 * Delivers pending AlertLog rows via log / email / Telegram (# Phase 2 channels).
 */
class DispatchAlertsJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(AlertDispatcher $dispatcher, PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('early-warning')) {
            Log::info('early-warning:dispatch-alerts skipped (plugin not installed)');

            return;
        }

        $sent = $dispatcher->dispatchPending();

        Log::info('early-warning:dispatch-alerts done', ['sent' => $sent]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('early-warning:dispatch-alerts failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
