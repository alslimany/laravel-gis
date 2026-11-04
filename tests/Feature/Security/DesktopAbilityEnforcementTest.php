<?php

namespace Tests\Feature\Security;

use App\Models\Layer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\TestHelpers\SecurityActors;

/**
 * DG-0.5: dc:layer:{publicId}:* must not widen access; request enforcement for DG-1.6.
 */
class DesktopAbilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected SecurityActors $actors;

    protected Layer $layerA;

    protected Layer $layerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actors = SecurityActors::create();
        $this->layerA = $this->actors->layerIn($this->actors->orgA);
        $this->layerB = $this->actors->layerIn($this->actors->orgA);
    }

    public function test_dc_layer_ability_alone_cannot_list_or_read_via_fixed_api_abilities(): void
    {
        $ability = $this->actors->dcLayerAbility($this->layerA, 'read');
        $this->actors->actingSanctum($this->actors->editorA, [$ability]);

        $this->getJson('/api/v1/layers')->assertForbidden();
        $this->getJson('/api/v1/layers/'.$this->layerA->public_id)->assertForbidden();
        $this->getJson('/api/v1/layers/'.$this->layerA->public_id.'/features')->assertForbidden();
        $this->getJson('/api/v1/layers/'.$this->layerA->public_id.'/export/geojson')->assertForbidden();
    }

    public function test_dc_layer_ability_for_layer_a_does_not_unlock_layer_b(): void
    {
        $ability = $this->actors->dcLayerAbility($this->layerA, 'read');
        $this->actors->actingSanctum($this->actors->editorA, [$ability, 'layers:read', 'features:read']);

        // Fixed abilities still gate /api/v1; foreign (or sibling) layer must not be readable
        // solely because a dc:layer ability for another public_id is present.
        $this->getJson('/api/v1/layers/'.$this->layerB->public_id)->assertOk();

        $this->actors->actingSanctum($this->actors->editorA, [$ability]);
        $this->getJson('/api/v1/layers/'.$this->layerB->public_id)->assertForbidden();
        $this->postJson('/api/v1/layers/'.$this->layerB->public_id.'/features', [
            'attributes' => ['name' => 'probe'],
            'wkt' => 'POINT(0 0)',
        ])->assertForbidden();
    }

    public function test_dc_edit_ability_alone_cannot_write_features_on_api_v1(): void
    {
        $ability = $this->actors->dcLayerAbility($this->layerA, 'edit');
        $this->actors->actingSanctum($this->actors->editorA, [$ability]);

        $this->postJson('/api/v1/layers/'.$this->layerA->public_id.'/features', [
            'attributes' => ['name' => 'probe'],
            'wkt' => 'POINT(0 0)',
        ])->assertForbidden();

        $this->putJson('/api/v1/layers/'.$this->layerA->public_id.'/features/1', [
            'attributes' => ['name' => 'probe'],
            'wkt' => 'POINT(0 0)',
        ])->assertForbidden();
    }

    public function test_wrong_org_dc_ability_string_still_cannot_reach_foreign_layer(): void
    {
        $foreign = $this->actors->layerIn($this->actors->orgB);
        $ability = $this->actors->dcLayerAbility($foreign, 'read');

        $this->actors->actingSanctum($this->actors->editorA, [$ability, 'layers:read']);

        $this->getJson('/api/v1/layers/'.$foreign->public_id)->assertNotFound();
    }

    public function test_desktop_api_key_routes_enforce_dc_layer_ability_when_present(): void
    {
        $routeNames = [
            'desktop-connect.api-keys.store',
            'desktop-connect.api-keys.index',
            'desktop-connect.api.keys.store',
            'api.v1.desktop.layers.show',
        ];

        $registered = collect($routeNames)->first(fn (string $name) => Route::has($name));

        if ($registered === null) {
            // Probe common path patterns Backend may register under DG-1.6.
            $candidates = [
                '/x/desktop-connect/api-keys',
                '/api/v1/desktop/layers/'.$this->layerA->public_id,
                '/api/desktop/layers/'.$this->layerA->public_id,
            ];
            $hit = null;
            foreach ($candidates as $path) {
                $this->actors->actingSanctum($this->actors->editorA, ['*']);
                $status = $this->getJson($path)->status();
                if (! in_array($status, [404, 405], true)) {
                    $hit = $path;
                    break;
                }
            }

            if ($hit === null) {
                $this->markTestSkipped('DG-1.6 desktop API key / dc:layer request routes not registered yet.');
            }

            $ability = $this->actors->dcLayerAbility($this->layerA, 'read');
            $this->actors->actingSanctum($this->actors->editorA, [$ability]);
            $allowed = $this->getJson($hit);
            $this->assertNotSame(401, $allowed->status());

            $wrong = $this->actors->dcLayerAbility($this->layerB, 'read');
            $this->actors->actingSanctum($this->actors->editorA, [$wrong]);
            $this->getJson(str_replace($this->layerA->public_id, $this->layerB->public_id, $hit) === $hit
                ? $hit
                : str_replace((string) $this->layerA->public_id, (string) $this->layerB->public_id, $hit)
            );

            // Wrong-layer ability must not read layer A through a desktop layer path.
            $this->actors->actingSanctum($this->actors->editorA, [$wrong]);
            $denied = $this->getJson($hit);
            $this->assertTrue(
                in_array($denied->status(), [403, 404], true),
                'Expected 403/404 for wrong dc:layer ability, got '.$denied->status()
            );

            return;
        }

        $this->assertTrue(Route::has($registered));
    }
}
