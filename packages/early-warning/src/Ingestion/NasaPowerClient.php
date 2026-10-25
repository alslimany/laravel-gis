<?php

namespace Lumina\EarlyWarning\Ingestion;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * NASA POWER hourly point API — operational bridge for IMERG-class precip
 * (PRECTOTCORR) and SMAP-class root-zone wetness (GWETROOT) until authenticated
 * GES DISC granule ingest is wired. No API key required.
 *
 * @see https://power.larc.nasa.gov/docs/services/api/temporal/hourly/
 */
class NasaPowerClient
{
    /**
     * @return list<array{
     *     observed_at: string,
     *     precipitation_mm: float|null,
     *     soil_moisture: float|null
     * }>
     */
    public function fetchHourly(float $lat, float $lon, Carbon $start, Carbon $end): array
    {
        if ($start->gt($end)) {
            throw new InvalidArgumentException('NASA POWER start must be before end.');
        }

        $cfg = config('early-warning.observations.nasa_power');
        $query = [
            'parameters' => implode(',', $cfg['parameters'] ?? ['PRECTOTCORR', 'GWETROOT']),
            'community' => $cfg['community'] ?? 'AG',
            'longitude' => $lon,
            'latitude' => $lat,
            'start' => $start->utc()->format('Ymd'),
            'end' => $end->utc()->format('Ymd'),
            'format' => 'JSON',
            'time-standard' => 'UTC',
        ];

        $timeout = max(5, (int) ($cfg['timeout_seconds'] ?? 60));
        $response = Http::connectTimeout(5)
            ->timeout($timeout)
            ->acceptJson()
            ->retry(2, 500)
            ->get($cfg['base_url'], $query)
            ->throw();

        $body = $response->json();
        if (! is_array($body)) {
            throw new RequestException($response);
        }

        $params = $body['properties']['parameter'] ?? null;
        if (! is_array($params)) {
            return [];
        }

        $precip = is_array($params['PRECTOTCORR'] ?? null) ? $params['PRECTOTCORR'] : [];
        $soil = is_array($params['GWETROOT'] ?? null) ? $params['GWETROOT'] : [];
        $keys = collect(array_keys($precip))->merge(array_keys($soil))->unique()->sort()->values();

        $fill = (float) ($cfg['fill_value'] ?? -999);
        $rows = [];
        foreach ($keys as $key) {
            $at = $this->parsePowerKey((string) $key);
            if ($at === null || $at->lt($start->copy()->startOfHour()) || $at->gt($end->copy()->endOfHour())) {
                continue;
            }

            $pRaw = $precip[$key] ?? null;
            $sRaw = $soil[$key] ?? null;
            $pMm = $this->numericOrNull($pRaw, $fill);
            $sVal = $this->numericOrNull($sRaw, $fill);
            if ($sVal !== null) {
                $sVal = max(0.0, min(1.0, $sVal));
            }

            if ($pMm === null && $sVal === null) {
                continue;
            }

            $rows[] = [
                'observed_at' => $at->toDateTimeString(),
                'precipitation_mm' => $pMm,
                'soil_moisture' => $sVal,
            ];
        }

        return $rows;
    }

    protected function parsePowerKey(string $key): ?Carbon
    {
        // YYYYMMDDHH
        if (! preg_match('/^\d{10}$/', $key)) {
            return null;
        }

        return Carbon::createFromFormat('YmdH', $key, 'UTC');
    }

    protected function numericOrNull(mixed $value, float $fill): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if ($n <= $fill + 0.0001) {
            return null;
        }

        return round($n, 4);
    }
}
