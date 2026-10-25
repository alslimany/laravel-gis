<?php

namespace Lumina\EarlyWarning\Listeners;

use App\Events\LayerDeleted;
use App\Events\LayerUpdated;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Log;

/**
 * When a Core layer that EW linked as basins changes, log for operators.
 * Full re-link / risk recompute can be queued once basins are Core-owned (Phase 0 follow-up).
 */
class RecomputeWhenCoreLayerChanges
{
    public function __construct(private PluginManager $plugins) {}

    public function handle(LayerUpdated|LayerDeleted $event): void
    {
        $layer = $event->layer;
        $meta = is_array($layer->metadata) ? $layer->metadata : [];
        if (($meta['plugin'] ?? null) !== 'early-warning' && ($meta['kind'] ?? null) !== 'basins') {
            return;
        }

        if (! $this->plugins->isInstalled('early-warning')) {
            return;
        }

        Log::info('early-warning.core_layer_changed', [
            'event' => $event::class,
            'layer_id' => $layer->id,
            'organization_id' => $layer->organization_id,
            'table_name' => $layer->table_name,
        ]);
    }
}
