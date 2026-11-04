<?php

namespace Lumina\DesktopConnect\Tests;

use App\Models\InstalledPlugin;
use App\Models\Layer;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginLifecycle;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Lumina\DesktopConnect\Models\ApiKey;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Services\DesktopKeyManager;
use Tests\TestCase;

/**
 * DG-1.6: mint/revoke UUID desktop keys; dc:layer:* + live grant enforcement.
 */
class DesktopApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'desktop-connect';

    protected Organization $org;

    protected Role $admin;

    protected Role $editor;

    protected PluginManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $this->editor = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $this->org = Organization::factory()->create();
        $this->plugins = app(PluginManager::class);

        app(PluginLifecycle::class)->install(self::KEY);
        $this->plugins->setEnabled(self::KEY, $this->org, true);
    }

    protected function user(bool $admin = false): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->roles()->attach($admin ? $this->admin : $this->editor);

        return $user->fresh();
    }

    protected function layer(): Layer
    {
        return Layer::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
        ])->fresh();
    }

    protected function grant(User $user, Layer $layer, string $scope = Grant::SCOPE_READ): Grant
    {
        return Grant::query()->create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'group_id' => null,
            'layer_id' => $layer->id,
            'scope' => $scope,
            'expires_at' => null,
            'granted_by' => $user->id,
        ]);
    }

    public function test_mint_issues_uuid_key_with_dc_layer_abilities(): void
    {
        $user = $this->user();
        $layer = $this->layer();
        $this->grant($user, $layer, Grant::SCOPE_EDIT);

        $this->actingAs($user)
            ->post('/x/desktop-connect/keys', ['label' => 'QGIS laptop'])
            ->assertRedirect(route('desktop-connect.grants.index'))
            ->assertSessionHas('desktopPlainTextKey');

        $plain = session('desktopPlainTextKey');
        $this->assertTrue(Str::isUuid($plain));

        $apiKey = ApiKey::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($apiKey);
        $this->assertSame('QGIS laptop', $apiKey->label);

        $token = PersonalAccessToken::query()->find($apiKey->personal_access_token_id);
        $this->assertNotNull($token);
        $this->assertContains("dc:layer:{$layer->public_id}:edit", $token->abilities);
        $this->assertContains("dc:layer:{$layer->public_id}:read", $token->abilities);
    }

    public function test_mint_requires_active_grant(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->from(route('desktop-connect.grants.index'))
            ->post('/x/desktop-connect/keys', ['label' => 'nope'])
            ->assertRedirect(route('desktop-connect.grants.index'))
            ->assertSessionHasErrors('label');

        $this->assertSame(0, ApiKey::query()->count());
    }

    public function test_desktop_api_allows_bearer_uuid_with_matching_ability(): void
    {
        $user = $this->user();
        $layer = $this->layer();
        $this->grant($user, $layer, Grant::SCOPE_READ);

        $minted = app(DesktopKeyManager::class)->mint($user, 'probe');

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layer->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $layer->public_id);
    }

    public function test_desktop_api_forbids_when_token_lacks_layer_ability(): void
    {
        $user = $this->user();
        $layerA = $this->layer();
        $layerB = $this->layer();
        $this->grant($user, $layerA, Grant::SCOPE_READ);

        $minted = app(DesktopKeyManager::class)->mint($user, 'probe');

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layerB->public_id)
            ->assertForbidden();
    }

    public function test_desktop_api_forbids_after_grant_revoked_even_if_token_still_has_ability(): void
    {
        $user = $this->user();
        $layer = $this->layer();
        $grant = $this->grant($user, $layer, Grant::SCOPE_READ);
        $minted = app(DesktopKeyManager::class)->mint($user, 'probe');

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layer->public_id)
            ->assertOk();

        $grant->delete();

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layer->public_id)
            ->assertForbidden();
    }

    public function test_desktop_api_forbids_expired_token(): void
    {
        $user = $this->user();
        $layer = $this->layer();
        $this->grant($user, $layer, Grant::SCOPE_READ);

        $minted = app(DesktopKeyManager::class)->mint(
            $user,
            'expiring',
            now()->addMinute()
        );

        PersonalAccessToken::query()
            ->whereKey($minted['key']->personal_access_token_id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layer->public_id)
            ->assertUnauthorized();
    }

    public function test_revoke_deletes_key_and_blocks_api(): void
    {
        $user = $this->user();
        $layer = $this->layer();
        $this->grant($user, $layer, Grant::SCOPE_READ);
        $minted = app(DesktopKeyManager::class)->mint($user, 'revoke-me');

        $this->actingAs($user)
            ->delete('/x/desktop-connect/keys/'.$minted['key']->id)
            ->assertRedirect(route('desktop-connect.grants.index'));

        $this->assertNull(ApiKey::query()->find($minted['key']->id));
        $this->assertNull(PersonalAccessToken::query()->find($minted['key']->personal_access_token_id));

        // Drop the session guard so the next call uses only the revoked Bearer token.
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layer->public_id)
            ->assertUnauthorized();
    }

    public function test_sequential_layer_id_does_not_bind_on_desktop_api(): void
    {
        $user = $this->user();
        $layer = $this->layer();
        $this->grant($user, $layer, Grant::SCOPE_READ);
        $minted = app(DesktopKeyManager::class)->mint($user, 'probe');

        $this->withToken($minted['plain_text'])
            ->getJson('/api/v1/desktop/layers/'.$layer->id)
            ->assertNotFound();
    }
}
