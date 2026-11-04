<?php

namespace Tests\Feature\Security;

use App\Models\Layer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SanctumAbilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Organization $otherOrganization;

    protected User $user;

    protected User $otherOrgUser;

    protected Layer $layer;

    protected Layer $otherOrgLayer;

    protected function setUp(): void
    {
        parent::setUp();

        $editor = Role::create(['name' => 'editor', 'description' => 'Editor']);

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->user->roles()->attach($editor);

        $this->otherOrgUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $this->otherOrgUser->roles()->attach($editor);

        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $this->layer = Layer::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'project_id' => $project->id,
        ]);

        $otherProject = Project::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $this->otherOrgLayer = Layer::factory()->create([
            'user_id' => $this->otherOrgUser->id,
            'organization_id' => $this->otherOrganization->id,
            'project_id' => $otherProject->id,
        ]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function abilityRouteMatrix(): array
    {
        return [
            // method, path template, required ability, wrong ability
            ['GET', 'layers', 'layers:read', 'features:read'],
            ['GET', 'layers/{layer}', 'layers:read', 'exports:read'],
            ['GET', 'layers/{layer}/geojson', 'features:read', 'exports:read'],
            ['GET', 'layers/{layer}/export/geojson', 'exports:read', 'layers:read'],
            ['GET', 'layers/{layer}/export/csv', 'exports:read', 'features:read'],
            ['GET', 'layers/{layer}/export/excel', 'exports:read', 'features:write'],
            ['GET', 'layers/{layer}/features', 'features:read', 'layers:read'],
            ['POST', 'layers/{layer}/features', 'features:write', 'features:read'],
            ['GET', 'layers/{layer}/features/1', 'features:read', 'layers:read'],
            ['PUT', 'layers/{layer}/features/1', 'features:write', 'features:read'],
            ['DELETE', 'layers/{layer}/features/1', 'features:write', 'exports:read'],
        ];
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_token_without_ability_is_forbidden(string $method, string $path, string $required, string $wrong): void
    {
        Sanctum::actingAs($this->user, []);

        $this->json($method, $this->url($path))->assertForbidden();
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_token_with_wrong_ability_is_forbidden(string $method, string $path, string $required, string $wrong): void
    {
        Sanctum::actingAs($this->user, [$wrong]);

        $this->json($method, $this->url($path))->assertForbidden();
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_token_with_right_ability_passes_ability_gate(string $method, string $path, string $required, string $wrong): void
    {
        Sanctum::actingAs($this->user, [$required]);

        $response = $this->json($method, $this->url($path), $this->bodyFor($method));

        // Ability middleware passed; downstream may 404/422/500 without PostGIS feature rows.
        $this->assertNotSame(401, $response->status(), $response->getContent());
        $this->assertNotSame(403, $response->status(), $response->getContent());
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_legacy_star_token_passes_ability_gate(string $method, string $path, string $required, string $wrong): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->json($method, $this->url($path), $this->bodyFor($method));

        $this->assertNotSame(401, $response->status(), $response->getContent());
        $this->assertNotSame(403, $response->status(), $response->getContent());
    }

    #[DataProvider('abilityRouteMatrix')]
    public function test_session_web_request_is_unaffected_by_ability_middleware(string $method, string $path, string $required, string $wrong): void
    {
        $response = $this->actingAs($this->user)->json($method, $this->url($path), $this->bodyFor($method));

        $this->assertNotSame(401, $response->status(), $response->getContent());
        $this->assertNotSame(403, $response->status(), $response->getContent());
    }

    public function test_geojson_allows_layers_read_or_features_read(): void
    {
        Sanctum::actingAs($this->user, ['layers:read']);
        $layersRead = $this->getJson($this->url('layers/{layer}/geojson'));
        $this->assertNotSame(403, $layersRead->status(), $layersRead->getContent());

        Sanctum::actingAs($this->user, ['features:read']);
        $featuresRead = $this->getJson($this->url('layers/{layer}/geojson'));
        $this->assertNotSame(403, $featuresRead->status(), $featuresRead->getContent());

        Sanctum::actingAs($this->user, ['exports:read']);
        $this->getJson($this->url('layers/{layer}/geojson'))->assertForbidden();
    }

    public function test_cross_org_layer_remains_denied_with_correct_abilities(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // Cross-org public_id is a scoped miss (404); sequential PK must not bind either.
        $this->getJson("/api/v1/layers/{$this->otherOrgLayer->public_id}")
            ->assertNotFound();

        $this->getJson("/api/v1/layers/{$this->otherOrgLayer->public_id}/features")
            ->assertNotFound();

        $this->getJson("/api/v1/layers/{$this->otherOrgLayer->id}")
            ->assertNotFound();
    }

    public function test_empty_abilities_token_cannot_list_layers(): void
    {
        $plain = $this->user->createToken('empty', [])->plainTextToken;

        $this->withToken($plain)
            ->getJson('/api/v1/layers')
            ->assertForbidden();
    }

    public function test_store_rejects_star_and_unknown_abilities(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/api-tokens', [
                'name' => 'bad-star',
                'abilities' => ['*'],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($this->user)
            ->post('/settings/api-tokens', [
                'name' => 'bad-unknown',
                'abilities' => ['admin:all'],
            ])
            ->assertSessionHasErrors('abilities.0');
    }

    public function test_store_accepts_catalog_abilities_and_does_not_default_to_star(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/api-tokens', [
                'name' => 'read-only',
                'abilities' => ['layers:read', 'features:read'],
            ])
            ->assertRedirect(route('api-tokens.index'));

        $token = $this->user->tokens()->where('name', 'read-only')->first();
        $this->assertNotNull($token);
        $this->assertSame(['layers:read', 'features:read'], $token->abilities);
        $this->assertNotContains('*', $token->abilities);
    }

    public function test_store_allows_empty_abilities_array(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/api-tokens', [
                'name' => 'denied',
                'abilities' => [],
            ])
            ->assertRedirect(route('api-tokens.index'));

        $token = $this->user->tokens()->where('name', 'denied')->first();
        $this->assertNotNull($token);
        $this->assertSame([], $token->abilities);
    }

    public function test_store_accepts_concrete_dc_layer_ability(): void
    {
        $ability = 'dc:layer:01ARZ3NDEKTSV4RRFFQ69G5FAV:read';

        $this->actingAs($this->user)
            ->post('/settings/api-tokens', [
                'name' => 'desktop',
                'abilities' => [$ability],
            ])
            ->assertRedirect(route('api-tokens.index'));

        $token = $this->user->tokens()->where('name', 'desktop')->first();
        $this->assertSame([$ability], $token->abilities);
    }

    public function test_index_marks_legacy_star_tokens_in_props(): void
    {
        $token = $this->user->createToken('legacy', ['*']);

        $this->actingAs($this->user)
            ->get(route('api-tokens.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ApiTokens')
                ->has('abilityCatalog')
                ->has('abilityCatalog.0.key')
                ->has('abilityCatalog.0.description')
                ->where('store_url', route('api-tokens.store'))
                ->has('tokens', 1)
                ->where('tokens.0.abilities', ['*'])
                ->where('tokens.0.destroy_url', route('api-tokens.destroy', $token->accessToken->id)));
    }

    public function test_index_localizes_ability_catalog_descriptions(): void
    {
        app()->setLocale('ar');

        $this->actingAs($this->user)
            ->get(route('api-tokens.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ApiTokens')
                ->where('abilityCatalog.0.key', 'layers:read')
                ->where('abilityCatalog.0.description', __('abilities.layers_read')));
    }

    public function test_destroy_revokes_own_token(): void
    {
        $token = $this->user->createToken('temp', ['layers:read']);

        $this->actingAs($this->user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id))
            ->assertRedirect(route('api-tokens.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, $this->user->tokens()->count());
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
