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
use Lumina\EarlyWarning\Ingestion\OpenMeteoClient;
use Lumina\EarlyWarning\Models\Basin;
use Lumina\EarlyWarning\Models\Forecast;
use Throwable;

/**
 * Global forecast ingest (#60, ADR D12/D14). Writes only ew_forecasts.
 */
class FetchForecastsJob implements GlobalJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(OpenMeteoClient $client, PluginManager $plugins): void
    {
        if (! $plugins->isInstalled('early-warning')) {
            Log::info('early-warning:fetch-forecasts skipped (plugin not installed)');

            return;
        }

        $basins = Basin::query()
            ->whereNotNull('outlet_lon')
            ->whereNotNull('outlet_lat')
            ->orderBy('basin_code')
            ->get(['basin_code', 'outlet_lon', 'outlet_lat']);

        if ($basins->isEmpty()) {
            Log::warning('early-warning:fetch-forecasts: no basins with outlets');

            return;
        }

        $batchSize = max(1, (int) config('early-warning.open_meteo.batch_size', 20));
        $fetchedAt = now();
        $upserted = 0;

        foreach ($basins->chunk($batchSize) as $chunk) {
            $points = $chunk->map(fn (Basin $b) => [
                'basin_code' => $b->basin_code,
                'lat' => (float) $b->outlet_lat,
                'lon' => (float) $b->outlet_lon,
            ])->values()->all();

            $locations = $client->fetchHourly($points);
            foreach ($locations as $loc) {
                $upserted += $this->storeLocation($loc, $fetchedAt);
            }
        }

        Log::info('early-warning:fetch-forecasts done', [
            'basins' => $basins->count(),
            'rows' => $upserted,
            'fetched_at' => $fetchedAt->toIso8601String(),
        ]);
    }

    /**
     * @param  array{basin_code: string, hourly: array<string, mixed>}  $loc
     */
    protected function storeLocation(array $loc, Carbon $fetchedAt): int
    {
        $hourly = $loc['hourly'];
        $times = $hourly['time'] ?? null;
        if (! is_array($times) || $times === []) {
            return 0;
        }

        $precip = $hourly['precipitation'] ?? [];
        $prob = $hourly['precipitation_probability'] ?? [];
        $temp = $hourly['temperature_2m'] ?? [];
        $rows = [];

        foreach ($times as $i => $time) {
            $rows[] = [
                'basin_code' => $loc['basin_code'],
                'source' => 'open-meteo',
                'fetched_at' => $fetchedAt->toDateTimeString(),
                'forecast_at' => Carbon::parse((string) $time, 'UTC')->utc()->toDateTimeString(),
                'precipitation_mm' => isset($precip[$i]) ? (float) $precip[$i] : null,
                'precipitation_probability' => isset($prob[$i]) ? (int) $prob[$i] : null,
                'temperature_c' => isset($temp[$i]) ? (float) $temp[$i] : null,
                'props' => null,
                'created_at' => $fetchedAt->toDateTimeString(),
                'updated_at' => $fetchedAt->toDateTimeString(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            Forecast::query()->upsert(
                $chunk,
                ['basin_code', 'source', 'forecast_at'],
                ['fetched_at', 'precipitation_mm', 'precipitation_probability', 'temperature_c', 'props', 'updated_at'],
            );
        }

        return count($rows);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('early-warning:fetch-forecasts failed', [
            'error' => $e?->getMessage(),
        ]);
    }
}
