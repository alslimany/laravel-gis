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
use Lumina\EarlyWarning\Exposure\BasinBbox;
use Lumina\EarlyWarning\Ingestion\OverpassClient;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\ExposureSummary;
use Throwable;

/**
 * Global OSM exposure rollups per basin (# Phase 3).
 * Writes only ew_exposure_summaries.
 */
class ComputeExposureJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public ?string $basinCode = null) {}

    public function handle(OverpassClient $overpass, BasinBbox $bboxes, PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('early-warning')) {
            Log::info('early-warning:compute-exposure skipped (plugin not installed)');

            return;
        }

        if (! config('early-warning.exposure.enabled', true)) {
            Log::info('early-warning:compute-exposure disabled by config');

            return;
        }

        $query = Basin::query()
            ->whereNotNull('outlet_lon')
            ->whereNotNull('outlet_lat')
            ->orderBy('basin_code');

        if ($this->basinCode) {
            $query->where('basin_code', $this->basinCode);
        }

        $basins = $query->get();
        if ($basins->isEmpty()) {
            Log::warning('early-warning:compute-exposure: no basins with outlets');

            return;
        }

        $updated = 0;
        foreach ($basins as $basin) {
            try {
                $box = $bboxes->for($basin);
                $stats = $overpass->exposureInBbox($box);
                $now = now();
                ExposureSummary::query()->updateOrCreate(
                    ['basin_code' => $basin->basin_code],
                    [
                        'building_count' => $stats['building_count'],
                        'road_segment_count' => $stats['road_segment_count'],
                        'road_km' => $stats['road_km'],
                        'bbox_south' => $box['south'],
                        'bbox_west' => $box['west'],
                        'bbox_north' => $box['north'],
                        'bbox_east' => $box['east'],
                        'source' => 'overpass',
                        'fetched_at' => $now,
                        'props' => [
                            'advisory' => 'OSM-derived exposure — completeness varies by area.',
                            'highway_filter' => 'motorway|trunk|primary|secondary|tertiary|unclassified|residential|living_street',
                        ],
                    ],
                );
                $updated++;
            } catch (Throwable $e) {
                Log::warning('early-warning:compute-exposure basin failed', [
                    'basin_code' => $basin->basin_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('early-warning:compute-exposure done', [
            'basins' => $basins->count(),
            'updated' => $updated,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('early-warning:compute-exposure failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
