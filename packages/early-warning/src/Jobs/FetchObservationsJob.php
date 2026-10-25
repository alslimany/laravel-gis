<?php

namespace Lumina\EarlyWarning\Jobs;

use App\Plugins\Contracts\GlobalJob;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Lumina\EarlyWarning\Ingestion\NasaPowerClient;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Observation;
use Throwable;

/**
 * Global IMERG/SMAP-class observation ingest via NASA POWER (Phase 2).
 * Writes only ew_observations.
 */
class FetchObservationsJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(NasaPowerClient $client, PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('early-warning')) {
            Log::info('early-warning:fetch-observations skipped (plugin not installed)');

            return;
        }

        if (! config('early-warning.observations.enabled', true)) {
            Log::info('early-warning:fetch-observations disabled by config');

            return;
        }

        $basins = Basin::query()
            ->whereNotNull('outlet_lon')
            ->whereNotNull('outlet_lat')
            ->orderBy('basin_code')
            ->get(['basin_code', 'outlet_lon', 'outlet_lat']);

        if ($basins->isEmpty()) {
            Log::warning('early-warning:fetch-observations: no basins with outlets');

            return;
        }

        $lookback = max(1, (int) config('early-warning.observations.lookback_days', 3));
        $end = now()->utc()->subHours((int) config('early-warning.observations.latency_hours', 6));
        $start = $end->copy()->subDays($lookback);
        $source = (string) config('early-warning.observations.source', 'nasa-power');
        $fetchedAt = now();
        $upserted = 0;

        foreach ($basins as $basin) {
            try {
                $rows = $client->fetchHourly(
                    (float) $basin->outlet_lat,
                    (float) $basin->outlet_lon,
                    $start,
                    $end,
                );
                $upserted += $this->store($basin->basin_code, $source, $rows, $fetchedAt);
            } catch (Throwable $e) {
                Log::warning('early-warning:fetch-observations basin failed', [
                    'basin_code' => $basin->basin_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('early-warning:fetch-observations done', [
            'basins' => $basins->count(),
            'rows' => $upserted,
            'source' => $source,
        ]);
    }

    /**
     * @param  list<array{observed_at: string, precipitation_mm: float|null, soil_moisture: float|null}>  $rows
     */
    protected function store(string $basinCode, string $source, array $rows, Carbon $fetchedAt): int
    {
        if ($rows === []) {
            return 0;
        }

        $ts = $fetchedAt->toDateTimeString();
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'basin_code' => $basinCode,
                'source' => $source,
                'observed_at' => $row['observed_at'],
                'fetched_at' => $ts,
                'precipitation_mm' => $row['precipitation_mm'],
                'soil_moisture' => $row['soil_moisture'],
                'props' => json_encode([
                    'provider' => 'nasa-power',
                    'products' => ['PRECTOTCORR', 'GWETROOT'],
                    'role' => 'imerg-smap-bridge',
                ]),
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        foreach (array_chunk($payload, 200) as $chunk) {
            Observation::query()->upsert(
                $chunk,
                ['basin_code', 'source', 'observed_at'],
                ['fetched_at', 'precipitation_mm', 'soil_moisture', 'props', 'updated_at'],
            );
        }

        return count($payload);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('early-warning:fetch-observations failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
