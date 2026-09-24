<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Services\SldGenerator;
use Tests\TestCase;

class MapIconTest extends TestCase
{
    public function test_icon_svg_uses_requested_color(): void
    {
        $response = $this->get('/map-icons/building-broadcast-tower.svg?color=%233388ff');

        $response->assertOk();
        $this->assertStringContainsString('image/svg+xml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('stroke="#3388ff"', $response->getContent());
        $this->assertStringNotContainsString('currentColor', $response->getContent());
    }

    public function test_unknown_icon_is_not_found(): void
    {
        $this->get('/map-icons/not-a-real-icon.svg')->assertNotFound();
    }

    public function test_point_sld_uses_external_graphic(): void
    {
        $layer = new Layer([
            'geometry_type' => 'Point',
            'table_name' => 'towers',
            'geoserver_layer_name' => 'towers',
        ]);

        $xml = app(SldGenerator::class)->generate($layer, [
            'renderer' => 'simple',
            'symbol' => [
                'icon' => 'building-broadcast-tower',
                'size' => 28,
                'fillColor' => '#112233',
            ],
        ]);

        $this->assertStringContainsString('ExternalGraphic', $xml);
        $this->assertStringContainsString('building-broadcast-tower.svg', $xml);
        $this->assertStringContainsString('<Size>28</Size>', $xml);
        $this->assertStringContainsString('%23112233', $xml);
    }

    public function test_circle_sld_stays_a_mark(): void
    {
        $layer = new Layer([
            'geometry_type' => 'Point',
            'table_name' => 'towers',
        ]);

        $xml = app(SldGenerator::class)->generate($layer, [
            'renderer' => 'simple',
            'symbol' => ['fillColor' => '#3388ff'],
        ]);

        $this->assertStringContainsString('<WellKnownName>circle</WellKnownName>', $xml);
        $this->assertStringNotContainsString('ExternalGraphic', $xml);
    }
}
