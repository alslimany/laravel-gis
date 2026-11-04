<?php

namespace Tests\Feature\Security;

use App\Models\Layer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\TestHelpers\SecurityActors;

/**
 * DG-0.5: Sanctum token × org IDOR on /api/v1 (public_id binding + list leakage).
 */
class OrgIsolationApiIdorTest extends TestCase
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
        $this->layerB = $this->actors->layerIn($this->actors->orgB);
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    public static function foreignLayerRoutes(): array
    {
        return [
            ['GET', 'layers/{layer}', ['layers:read']],
            ['GET', 'layers/{layer}/geojson', ['layers:read']],
            ['GET', 'layers/{layer}/export/geojson', ['exports:read']],
            ['GET', 'layers/{layer}/export/csv', ['exports:read']],
            ['GET', 'layers/{layer}/export/excel', ['exports:read']],
            ['GET', 'layers/{layer}/features', ['features:read']],
            ['POST', 'layers/{layer}/features', ['features:write']],
            ['GET', 'layers/{layer}/features/1', ['features:read']],
            ['PUT', 'layers/{layer}/features/1', ['features:write']],
            ['DELETE', 'layers/{layer}/features/1', ['features:write']],
        ];
    }

    #[DataProvider('foreignLayerRoutes')]
    public function test_foreign_public_id_is_not_found_with_correct_abilities(string $method, string $path, array $abilities): void
    {
        $this->actors->actingSanctum($this->actors->editorA, $abilities);

        $this->json($method, $this->url($path, $this->layerB), $this->bodyFor($method))
            ->assertNotFound();
    }

    #[DataProvider('foreignLayerRoutes')]
    public function test_foreign_public_id_is_not_found_with_star_ability(string $method, string $path, array $abilities): void
    {
        $this->actors->actingSanctum($this->actors->editorA, ['*']);

        $this->json($method, $this->url($path, $this->layerB), $this->bodyFor($method))
            ->assertNotFound();
    }

    public function test_sequential_pk_does_not_bind_on_api_v1(): void
    {
        $this->actors->actingSanctum($this->actors->editorA, ['*']);

        $this->getJson('/api/v1/layers/'.$this->layerB->id)->assertNotFound();
        $this->getJson('/api/v1/layers/'.$this->layerA->id)->assertNotFound();
        $this->postJson('/api/v1/layers/'.$this->layerB->id.'/features', [
            'attributes' => ['name' => 'probe'],
            'wkt' => 'POINT(0 0)',
        ])->assertNotFound();
    }

    public function test_layer_index_never_includes_foreign_org_public_ids(): void
    {
        $this->actors->actingSanctum($this->actors->editorA, ['layers:read']);

        $response = $this->getJson('/api/v1/layers')->assertOk();

        $ids = collect(data_get($response->json(), 'data', []))
            ->merge(data_get($response->json(), 'data.data', []))
            ->pluck('public_id')
            ->filter()
            ->values();

        // Paginated JSON may nest under data.data — accept either shape.
        $publicIds = collect($response->json('data'))
            ->map(fn ($row) => is_array($row) ? ($row['public_id'] ?? null) : null)
            ->filter()
            ->values();

        if ($publicIds->isEmpty() && is_array($response->json('data.data'))) {
            $publicIds = collect($response->json('data.data'))->pluck('public_id')->filter()->values();
        }

        $this->assertTrue($publicIds->contains($this->layerA->public_id));
        $this->assertFalse($publicIds->contains($this->layerB->public_id));
        $this->assertFalse($ids->contains($this->layerB->public_id));
    }

    public function test_own_org_layer_show_passes_ability_and_org_gate(): void
    {
        $this->actors->actingSanctum($this->actors->editorA, ['layers:read']);

        $this->getJson('/api/v1/layers/'.$this->layerA->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $this->layerA->public_id)
            ->assertJsonMissing(['id' => $this->layerA->id]);
    }

    protected function url(string $path, Layer $layer): string
    {
        return '/api/v1/'.str_replace('{layer}', (string) $layer->public_id, $path);
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
