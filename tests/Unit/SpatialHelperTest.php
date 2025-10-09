<?php

namespace Tests\Unit;

use App\Helpers\SpatialHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpatialHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_point()
    {
        $point = SpatialHelper::makePoint(37.7749, -122.4194);
        
        $this->assertEquals('POINT(-122.4194 37.7749)', $point);
    }

    public function test_can_create_linestring()
    {
        $coordinates = [
            [-122.5, 37.7],
            [-122.4, 37.8],
            [-122.3, 37.9],
        ];
        
        $linestring = SpatialHelper::makeLineString($coordinates);
        
        $this->assertEquals('LINESTRING(-122.5 37.7, -122.4 37.8, -122.3 37.9)', $linestring);
    }

    public function test_can_create_polygon()
    {
        $coordinates = [
            [-122.5, 37.7],
            [-122.3, 37.7],
            [-122.3, 37.8],
            [-122.5, 37.8],
        ];
        
        $polygon = SpatialHelper::makePolygon($coordinates);
        
        $this->assertStringContainsString('POLYGON', $polygon);
        $this->assertStringContainsString('-122.5 37.7', $polygon);
    }

    public function test_can_calculate_distance()
    {
        // Distance between two points approximately 111km apart (1 degree latitude)
        $distance = SpatialHelper::distance(0, 0, 1, 0);
        
        $this->assertGreaterThan(100000, $distance); // ~111km in meters
        $this->assertLessThan(120000, $distance);
    }

    public function test_can_calculate_buffer()
    {
        $point = 'POINT(0 0)';
        $buffered = SpatialHelper::buffer($point, 100);
        
        $this->assertStringContainsString('POLYGON', $buffered);
    }

    public function test_can_check_intersects()
    {
        $polygon1 = 'POLYGON((0 0, 2 0, 2 2, 0 2, 0 0))';
        $polygon2 = 'POLYGON((1 1, 3 1, 3 3, 1 3, 1 1))';
        
        $intersects = SpatialHelper::intersects($polygon1, $polygon2);
        
        $this->assertTrue($intersects);
    }

    public function test_can_check_not_intersects()
    {
        $polygon1 = 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))';
        $polygon2 = 'POLYGON((2 2, 3 2, 3 3, 2 3, 2 2))';
        
        $intersects = SpatialHelper::intersects($polygon1, $polygon2);
        
        $this->assertFalse($intersects);
    }

    public function test_can_check_contains()
    {
        $outer = 'POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))';
        $inner = 'POLYGON((1 1, 2 1, 2 2, 1 2, 1 1))';
        
        $contains = SpatialHelper::contains($outer, $inner);
        
        $this->assertTrue($contains);
    }

    public function test_can_check_within()
    {
        $inner = 'POLYGON((1 1, 2 1, 2 2, 1 2, 1 1))';
        $outer = 'POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))';
        
        $within = SpatialHelper::within($inner, $outer);
        
        $this->assertTrue($within);
    }

    public function test_can_calculate_area()
    {
        // A 1 degree by 1 degree square at the equator
        $polygon = 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))';
        
        $area = SpatialHelper::area($polygon);
        
        // Should be roughly 12,364 km² (1 degree at equator)
        $this->assertGreaterThan(10000000000, $area); // In square meters
    }

    public function test_can_calculate_length()
    {
        // A line approximately 111km long (1 degree latitude)
        $line = 'LINESTRING(0 0, 0 1)';
        
        $length = SpatialHelper::length($line);
        
        $this->assertGreaterThan(100000, $length); // ~111km in meters
        $this->assertLessThan(120000, $length);
    }

    public function test_can_check_point_in_polygon()
    {
        $polygon = 'POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))';
        
        $isInside = SpatialHelper::pointInPolygon(2, 2, $polygon);
        $isOutside = SpatialHelper::pointInPolygon(5, 5, $polygon);
        
        $this->assertTrue($isInside);
        $this->assertFalse($isOutside);
    }

    public function test_can_get_centroid()
    {
        $polygon = 'POLYGON((0 0, 4 0, 4 4, 0 4, 0 0))';
        
        [$lat, $lon] = SpatialHelper::centroid($polygon);
        
        $this->assertEquals(2.0, $lat);
        $this->assertEquals(2.0, $lon);
    }

    public function test_can_get_bounding_box()
    {
        $polygon = 'POLYGON((1 1, 3 1, 3 3, 1 3, 1 1))';
        
        [$minLon, $minLat, $maxLon, $maxLat] = SpatialHelper::boundingBox($polygon);
        
        $this->assertEquals(1.0, $minLon);
        $this->assertEquals(1.0, $minLat);
        $this->assertEquals(3.0, $maxLon);
        $this->assertEquals(3.0, $maxLat);
    }

    public function test_can_convert_wkt_to_geojson()
    {
        $wkt = 'POINT(-122.4194 37.7749)';
        
        $geojson = SpatialHelper::wktToGeoJson($wkt);
        
        $this->assertIsArray($geojson);
        $this->assertEquals('Point', $geojson['type']);
        $this->assertIsArray($geojson['coordinates']);
    }

    public function test_can_convert_geojson_to_wkt()
    {
        $geojson = [
            'type' => 'Point',
            'coordinates' => [-122.4194, 37.7749],
        ];
        
        $wkt = SpatialHelper::geoJsonToWkt($geojson);
        
        $this->assertStringContainsString('POINT', $wkt);
        $this->assertStringContainsString('-122.4194', $wkt);
        $this->assertStringContainsString('37.7749', $wkt);
    }
}
