<?php

namespace Lumina\EarlyWarning\Tests;

use PHPUnit\Framework\TestCase;

/** #59: shape checks on the committed data/out/tripoli_basins.geojson (no DB). */
class CommittedBasinsGeojsonTest extends TestCase
{
    private array $fc;

    protected function setUp(): void
    {
        $path = dirname(__DIR__).'/data/out/tripoli_basins.geojson';
        $this->assertFileExists($path);
        $this->fc = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_features_sorted_by_basin_code_with_snap_metadata(): void
    {
        $codes = array_map(fn ($f) => $f['properties']['basin_code'], $this->fc['features']);
        $sorted = $codes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $codes);
        $this->assertSame(['TRP-HIRA', 'TRP-MAJENIN', 'TRP-RABIE'], $codes);

        foreach ($this->fc['features'] as $f) {
            $p = $f['properties'];
            foreach (['outlet_input_lon', 'outlet_input_lat', 'snapped_lon', 'snapped_lat', 'snap_dist_m', 'wbt_version', 'processed_at'] as $k) {
                $this->assertArrayHasKey($k, $p, "{$p['basin_code']} missing {$k}");
            }
            $this->assertLessThanOrEqual($p['snap_max_m'], $p['snap_dist_m']);
            $this->assertGreaterThanOrEqual($p['stream_threshold_km2'], $p['area_km2']);
        }
    }

    public function test_basin_polygons_have_no_interior_rings(): void
    {
        foreach ($this->fc['features'] as $f) {
            $g = $f['geometry'];
            $this->assertContains($g['type'], ['Polygon', 'MultiPolygon']);
            $polys = $g['type'] === 'Polygon' ? [$g['coordinates']] : $g['coordinates'];
            foreach ($polys as $rings) {
                $this->assertCount(1, $rings, "{$f['id']} has interior ring(s) (holes)");
            }
        }
    }

    public function test_coordinates_rounded_to_six_decimals(): void
    {
        $raw = file_get_contents(dirname(__DIR__).'/data/out/tripoli_basins.geojson');
        $this->assertDoesNotMatchRegularExpression('/\d\.\d{7,}/', $raw);
    }
}
