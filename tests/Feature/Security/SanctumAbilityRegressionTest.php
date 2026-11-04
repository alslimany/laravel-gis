<?php

namespace Tests\Feature\Security;

use App\Models\Layer;
use App\Support\LuminaAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\TestHelpers\SecurityActors;

/**
 * DG-0.5 regression pins for DG-0.4 Sanctum abilities (mint + request gates).
 */
class SanctumAbilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected SecurityActors $actors;

    protected Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actors = SecurityActors::create();
        $this->layer = $this->actors->layerIn($this->actors->orgA);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function abilityRouteMatrix(): array
    {
        return [
            ['GET', 'layers', 'layers:read', 'features:read'],
            ['GET', 'layers/{layer}', 'layers:read', 'exports:read'],
            ['GET', 'layers/{layer}/geojson', 'features:read', 'exports:read'],
            ['GET', 'layers/{layer}/export/geojson', 'exports:read', 'layers:read'],
            ['GET', 'layers/{layer}/features', 'features:read', 'layers:read'],
            ['POST', 'layers/{layer}/features', 'features:write', 'features:read'],
            ['PUT', 'layers/{layer}/features/1', 'features:write', 'features:read'],
            ['DELETE', 'layers/{layer}/features/1', 'features:write', 'exports:read'],
        ];
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_empty_abilities_are_forbidden(string $method, string $path, string $required, string $wrong): void
    {
        $this->actors->actingSanctum($this->actors->editorA, []);
        $this->json($method, $this->url($path))->assertForbidden();
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_wrong_ability_is_forbidden(string $method, string $path, string $required, string $wrong): void
    {
        $this->actors->actingSanctum($this->actors->editorA, [$wrong]);
        $this->json($method, $this->url($path))->assertForbidden();
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_right_ability_passes_ability_gate(string $method, string $path, string $required, string $wrong): void
    {
        $this->actors->actingSanctum($this->actors->editorA, [$required]);
        $response = $this->json($method, $this->url($path), $this->bodyFor($method));

        $this->assertNotSame(401, $response->status(), $response->getContent());
        $this->assertNotSame(403, $response->status(), $response->getContent());
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_legacy_star_passes_ability_gate(string $method, string $path, string $required, string $wrong): void
    {
        $this->actors->actingSanctum($this->actors->editorA, ['*']);
        $response = $this->json($method, $this->url($path), $this->bodyFor($method));

        $this->assertNotSame(401, $response->status(), $response->getContent());
        $this->assertNotSame(403, $response->status(), $response->getContent());
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_session_web_request_bypasses_ability_middleware(string $method, string $path, string $required, string $wrong): void
    {
        $response = $this->actingAs($this->actors->editorA)
            ->json($method, $this->url($path), $this->bodyFor($method));

        $this->assertNotSame(401, $response->status(), $response->getContent());
        $this->assertNotSame(403, $response->status(), $response->getContent());
    }

    public function test_mint_rejects_star_and_unknown_and_does_not_default_to_star(): void
    {
        $this->actingAs($this->actors->editorA)
            ->post('/settings/api-tokens', [
                'name' => 'bad-star',
                'abilities' => ['*'],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($this->actors->editorA)
            ->post('/settings/api-tokens', [
                'name' => 'bad-unknown',
                'abilities' => ['admin:all'],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($this->actors->editorA)
            ->post('/settings/api-tokens', [
                'name' => 'read-only',
                'abilities' => ['layers:read'],
            ])
            ->assertRedirect(route('api-tokens.index'));

        $token = $this->actors->editorA->tokens()->where('name', 'read-only')->first();
        $this->assertNotNull($token);
        $this->assertSame(['layers:read'], $token->abilities);
        $this->assertNotContains('*', $token->abilities);
    }

    public function test_catalog_and_dc_pattern_validation(): void
    {
        $this->assertTrue(LuminaAbilities::isAllowed('layers:read'));
        $this->assertFalse(LuminaAbilities::isAllowed('*'));
        $this->assertFalse(LuminaAbilities::isAllowed('admin:all'));

        $valid = $this->actors->dcLayerAbility($this->layer, 'read');
        $this->assertTrue(LuminaAbilities::isAllowed($valid));
        $this->assertFalse(LuminaAbilities::isAllowed('dc:layer:not-a-ulid:read'));
    }

    protected function url(string $path): string
    {
        return '/api/v1/'.str_replace('{layer}', (string) $this->layer->public_id, $path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyFor(string $method): array
    {
        return in_array($method, ['POST', 'PUT'], true)
            ? ['attributes' => ['name' => 'probe'], 'wkt' => 'POINT(0 0)']
            : [];
    }
}
