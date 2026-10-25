<?php

namespace Lumina\EarlyWarning\Ingestion;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Open-Meteo Forecast API client (no API key). Uses Laravel Http facade only
 * (ADR arch: no raw HTTP in packages/*).
 */
class OpenMeteoClient
{
    /**
     * @param  list<array{basin_code: string, lat: float, lon: float}>  $points
     * @return list<array{
     *     basin_code: string,
     *     latitude: float,
     *     longitude: float,
     *     hourly: array{time: list<string>, precipitation?: list<?float>, precipitation_probability?: list<?int>, temperature_2m?: list<?float>}
     * }>
     */
    public function fetchHourly(array $points): array
    {
        if ($points === []) {
            return [];
        }

        foreach ($points as $i => $p) {
            if (! isset($p['basin_code'], $p['lat'], $p['lon'])) {
                throw new InvalidArgumentException("Point #{$i} needs basin_code, lat, lon.");
            }
        }

        $cfg = config('early-warning.open_meteo');
        $query = [
            'latitude' => implode(',', array_map(fn ($p) => (string) $p['lat'], $points)),
            'longitude' => implode(',', array_map(fn ($p) => (string) $p['lon'], $points)),
            'hourly' => implode(',', $cfg['hourly']),
            'forecast_days' => $cfg['forecast_days'],
            'timezone' => $cfg['timezone'],
        ];

        $response = Http::timeout($cfg['timeout_seconds'])
            ->acceptJson()
            ->get($cfg['base_url'], $query)
            ->throw();

        $body = $response->json();
        if (! is_array($body)) {
            throw new RequestException($response);
        }

        // Single location → object; multi → list of objects.
        $locations = array_is_list($body) ? $body : [$body];
        if (count($locations) !== count($points)) {
            throw new InvalidArgumentException(sprintf(
                'Open-Meteo returned %d location(s) for %d point(s).',
                count($locations),
                count($points),
            ));
        }

        $out = [];
        foreach ($locations as $i => $loc) {
            if (! is_array($loc) || ! isset($loc['hourly']) || ! is_array($loc['hourly'])) {
                throw new InvalidArgumentException("Open-Meteo location #{$i} missing hourly block.");
            }
            $out[] = [
                'basin_code' => $points[$i]['basin_code'],
                'latitude' => (float) ($loc['latitude'] ?? $points[$i]['lat']),
                'longitude' => (float) ($loc['longitude'] ?? $points[$i]['lon']),
                'hourly' => $loc['hourly'],
            ];
        }

        return $out;
    }
}
