<?php

namespace Lumina\EarlyWarning\Console;

use Illuminate\Console\Command;
use Lumina\EarlyWarning\Models\Forecast;

/**
 * Suggest AlertRule thresholds from local Open-Meteo history.
 * Full CHIRPS / Storm Daniel calibration is a later data-import step; this
 * uses forecast archives already in ew_forecasts to propose yellow/orange/red.
 */
class SuggestThresholdsCommand extends Command
{
    protected $signature = 'early-warning:suggest-thresholds
        {--basin= : Limit to one basin_code}
        {--days=30 : Look-back window}';

    protected $description = 'Suggest precip AlertRule thresholds from local forecast history';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $from = now()->utc()->subDays($days);

        $query = Forecast::query()
            ->where('source', 'open-meteo')
            ->where('forecast_at', '>=', $from)
            ->whereNotNull('precipitation_mm');

        if ($this->option('basin')) {
            $query->where('basin_code', (string) $this->option('basin'));
        }

        $rows = $query
            ->orderBy('basin_code')
            ->orderBy('forecast_at')
            ->get(['basin_code', 'forecast_at', 'precipitation_mm']);

        if ($rows->isEmpty()) {
            $this->warn('No forecast rows in the look-back window. Fetch forecasts first.');

            return self::SUCCESS;
        }

        $byBasin = $rows->groupBy('basin_code');
        $this->info("Look-back {$days}d · basins {$byBasin->count()} · rows {$rows->count()}");
        $this->newLine();
        $this->line('Tripoli defaults (config) stay conservative vs Storm Daniel / Derna extremes.');
        $this->line('Suggestions use p90 / p95 / p99 of rolling 6h precip per basin.');
        $this->newLine();

        $headers = ['basin', 'hours', 'p90_6h', 'p95_6h', 'p99_6h', 'max_6h'];
        $table = [];

        foreach ($byBasin as $code => $forecasts) {
            $byHour = [];
            foreach ($forecasts as $row) {
                $byHour[$row->forecast_at->utc()->format('Y-m-d H:00:00')] = (float) $row->precipitation_mm;
            }
            ksort($byHour);
            $keys = array_keys($byHour);
            $windows = [];
            foreach ($keys as $i => $key) {
                if ($i < 5) {
                    continue;
                }
                $sum = 0.0;
                for ($j = 0; $j < 6; $j++) {
                    $sum += $byHour[$keys[$i - $j]] ?? 0.0;
                }
                $windows[] = round($sum, 3);
            }
            if ($windows === []) {
                continue;
            }
            sort($windows);
            $n = count($windows);
            $table[] = [
                $code,
                (string) $n,
                (string) $this->percentile($windows, 90),
                (string) $this->percentile($windows, 95),
                (string) $this->percentile($windows, 99),
                (string) max($windows),
            ];
        }

        $this->table($headers, $table);
        $this->newLine();
        $this->line('Map suggestions → AlertRule: yellow≈p90, orange≈p95, red≈min(p99, 70) unless calibration overrides.');

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $sortedAscending
     */
    protected function percentile(array $sortedAscending, float $p): float
    {
        $n = count($sortedAscending);
        if ($n === 0) {
            return 0.0;
        }
        $rank = (int) ceil(($p / 100) * $n) - 1;

        return round($sortedAscending[max(0, min($n - 1, $rank))], 2);
    }
}
