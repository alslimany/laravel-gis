<?php

namespace Lumina\EarlyWarning\Exposure;

use Lumina\EarlyWarning\Models\Basin;

/** Derive a query bbox around a basin outlet from area (or a minimum pad). */
class BasinBbox
{
    /**
     * @return array{south: float, west: float, north: float, east: float}
     */
    public function for(Basin $basin): array
    {
        $lat = (float) $basin->outlet_lat;
        $lon = (float) $basin->outlet_lon;
        $area = $basin->area_km2 !== null ? max(1.0, (float) $basin->area_km2) : 25.0;

        // Circle radius covering the catchment, padded 20%.
        $radiusKm = sqrt($area / M_PI) * 1.2;
        $radiusKm = max(1.5, min(40.0, $radiusKm));

        $degLat = $radiusKm / 111.0;
        $cos = max(0.2, cos(deg2rad($lat)));
        $degLon = $radiusKm / (111.0 * $cos);

        return [
            'south' => round($lat - $degLat, 6),
            'west' => round($lon - $degLon, 6),
            'north' => round($lat + $degLat, 6),
            'east' => round($lon + $degLon, 6),
        ];
    }
}
