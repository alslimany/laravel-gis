<?php

namespace Lumina\EarlyWarning\Ingestion;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * OpenStreetMap Overpass API — building counts + highway lengths in a bbox.
 *
 * @see https://wiki.openstreetmap.org/wiki/Overpass_API
 */
class OverpassClient
{
    /**
     * @param  array{south: float, west: float, north: float, east: float}  $bbox
     * @return array{building_count: int, road_segment_count: int, road_km: float}
     */
    public function exposureInBbox(array $bbox): array
    {
        foreach (['south', 'west', 'north', 'east'] as $k) {
            if (! isset($bbox[$k]) || ! is_numeric($bbox[$k])) {
                throw new InvalidArgumentException("Bbox needs numeric {$k}.");
            }
        }
        if ($bbox['south'] >= $bbox['north'] || $bbox['west'] >= $bbox['east']) {
            throw new InvalidArgumentException('Invalid bbox ordering.');
        }

        $s = (float) $bbox['south'];
        $w = (float) $bbox['west'];
        $n = (float) $bbox['north'];
        $e = (float) $bbox['east'];
        $bb = "{$s},{$w},{$n},{$e}";

        $buildings = $this->query(<<<QL
[out:json][timeout:90];
(
  way["building"]({$bb});
  relation["building"]({$bb});
);
out count;
QL);

        $roads = $this->query(<<<QL
[out:json][timeout:90];
way["highway"~"motorway|trunk|primary|secondary|tertiary|unclassified|residential|living_street"]({$bb});
out geom;
QL);

        $buildingCount = $this->parseCount($buildings);
        [$segments, $km] = $this->parseRoadGeom($roads);

        return [
            'building_count' => $buildingCount,
            'road_segment_count' => $segments,
            'road_km' => round($km, 3),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(string $ql): array
    {
        $cfg = config('early-warning.exposure.overpass');
        $timeout = max(10, (int) ($cfg['timeout_seconds'] ?? 90));
        $url = (string) ($cfg['base_url'] ?? 'https://overpass-api.de/api/interpreter');

        $response = Http::connectTimeout(5)
            ->timeout($timeout)
            ->asForm()
            ->acceptJson()
            ->retry(2, 1000)
            ->post($url, ['data' => $ql])
            ->throw();

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function parseCount(array $body): int
    {
        foreach ($body['elements'] ?? [] as $el) {
            if (($el['type'] ?? '') === 'count') {
                $tags = $el['tags'] ?? [];

                return (int) ($tags['total'] ?? $tags['ways'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: float}
     */
    protected function parseRoadGeom(array $body): array
    {
        $segments = 0;
        $km = 0.0;
        foreach ($body['elements'] ?? [] as $el) {
            if (($el['type'] ?? '') !== 'way') {
                continue;
            }
            $geometry = $el['geometry'] ?? null;
            if (! is_array($geometry) || count($geometry) < 2) {
                continue;
            }
            $segments++;
            for ($i = 1; $i < count($geometry); $i++) {
                $a = $geometry[$i - 1];
                $b = $geometry[$i];
                if (! isset($a['lat'], $a['lon'], $b['lat'], $b['lon'])) {
                    continue;
                }
                $km += $this->haversineKm(
                    (float) $a['lat'],
                    (float) $a['lon'],
                    (float) $b['lat'],
                    (float) $b['lon'],
                );
            }
        }

        return [$segments, $km];
    }

    protected function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
