<?php

namespace Lumina\Disaster\Tests;

use Illuminate\Support\Facades\DB;
use Lumina\Disaster\Support\GeometryReader;
use Tests\TestCase;

class GeometryReaderTest extends TestCase
{
    private const SQUARE = '{"type":"Polygon","coordinates":[[[13.0,32.0],[13.2,32.0],[13.2,32.2],[13.0,32.2],[13.0,32.0]]]}';

    public function test_point_still_reads_exactly(): void
    {
        $c = GeometryReader::pointCoordinates('{"type":"Point","coordinates":[13.19,32.88]}');
        $this->assertEqualsWithDelta(32.88, $c['latitude'], 1e-9);
        $this->assertEqualsWithDelta(13.19, $c['longitude'], 1e-9);
    }

    public function test_polygon_asset_returns_a_point_instead_of_crashing(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $c = GeometryReader::pointCoordinates(DB::selectOne(
                'SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) as g', [self::SQUARE])->g);
        } else {
            $c = GeometryReader::pointCoordinates(self::SQUARE);
        }

        $this->assertNotNull($c);
        $this->assertTrue($c['latitude'] >= 32.0 && $c['latitude'] <= 32.2);
        $this->assertTrue($c['longitude'] >= 13.0 && $c['longitude'] <= 13.2);
    }
}
