<?php

namespace Lumina\Disaster\Jobs;

use App\Plugins\Contracts\GlobalJob;
use App\Plugins\PluginManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Lumina\Disaster\Models\AvlPing;

class PruneAvlJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('disaster')) {
            Log::info('disaster:prune-avl skipped (plugin not installed)');

            return;
        }

        $days = (int) config('disaster.retention_avl_days', 14);
        $cutoff = now()->subDays($days);

        $deleted = AvlPing::query()->where('recorded_at', '<', $cutoff)->delete();
        Log::info('disaster:prune-avl completed', ['deleted' => $deleted, 'cutoff' => $cutoff->toIso8601String()]);
    }
}
