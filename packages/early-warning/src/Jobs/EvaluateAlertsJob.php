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
use Lumina\EarlyWarning\Alerts\AlertRuleEvaluator;
use Throwable;

/**
 * Org-scoped AlertRule evaluation → AlertLog (# Phase 2).
 * Marker is GlobalJob: iterates enabled orgs; does not send Telegram/email yet.
 */
class EvaluateAlertsJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function handle(AlertRuleEvaluator $evaluator, PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('early-warning')) {
            Log::info('early-warning:evaluate-alerts skipped (plugin not installed)');

            return;
        }

        $orgIds = $plugins->enabledOrganizations('early-warning');
        if ($orgIds->isEmpty()) {
            Log::info('early-warning:evaluate-alerts: no enabled organizations');

            return;
        }

        $logged = 0;
        foreach ($orgIds as $organizationId) {
            $logged += $evaluator->evaluateOrganization((int) $organizationId);
        }

        Log::info('early-warning:evaluate-alerts done', [
            'organizations' => $orgIds->count(),
            'logs' => $logged,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('early-warning:evaluate-alerts failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
