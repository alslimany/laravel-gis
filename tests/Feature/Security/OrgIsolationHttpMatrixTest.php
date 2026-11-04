<?php

namespace Tests\Feature\Security;

use App\Models\DashboardBoard;
use App\Models\DataImport;
use App\Models\Form;
use App\Models\Group;
use App\Models\Layer;
use App\Models\Map;
use App\Models\Project;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\TestHelpers\SecurityActors;

/**
 * DG-0.5: session HTTP cross-org isolation for maps, MVT, groups, webhooks, and core resources.
 */
class OrgIsolationHttpMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected SecurityActors $actors;

    protected Project $projectB;

    protected Layer $layerB;

    protected Map $mapB;

    protected Form $formB;

    protected DashboardBoard $dashboardB;

    protected Webhook $webhookB;

    protected Group $groupB;

    protected DataImport $importB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actors = SecurityActors::create();
        $this->projectB = $this->actors->projectIn($this->actors->orgB);
        $this->layerB = $this->actors->layerIn($this->actors->orgB, $this->actors->editorB, $this->projectB);
        $this->mapB = $this->actors->mapIn($this->actors->orgB, $this->actors->editorB, public: false);
        $this->formB = $this->actors->formIn($this->actors->orgB, $this->actors->editorB, $this->layerB);
        $this->dashboardB = $this->actors->dashboardIn($this->actors->orgB, $this->actors->editorB);
        $this->webhookB = $this->actors->webhookIn($this->actors->orgB, 'secret-org-b');
        $this->groupB = $this->actors->groupIn($this->actors->orgB);
        $this->importB = $this->actors->importIn($this->actors->orgB, $this->actors->editorB);
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function foreignResourceDenied(): array
    {
        return [
            // method, path key, expected status
            ['GET', 'projects.show', 403],
            ['GET', 'layers.show', 403],
            ['GET', 'maps.show', 403],
            ['GET', 'maps.edit', 403],
            ['DELETE', 'maps.destroy', 403],
            ['GET', 'forms.show', 403],
            ['GET', 'forms.edit', 403],
            ['GET', 'dashboards.show', 403],
            ['GET', 'dashboards.edit', 403],
            ['GET', 'dashboards.data', 403],
            ['GET', 'imports.show', 403],
            ['DELETE', 'imports.destroy', 403],
            ['GET', 'groups.show', 403],
            ['GET', 'groups.edit', 403],
            ['PUT', 'groups.update', 403],
            ['DELETE', 'groups.destroy', 403],
            ['GET', 'webhooks.edit', 403],
            ['PUT', 'webhooks.update', 403],
            ['DELETE', 'webhooks.destroy', 403],
            ['GET', 'mvt.tiles', 403],
        ];
    }

    #[DataProvider('foreignResourceDenied')]
    public function test_org_a_actor_cannot_access_org_b_resources(string $method, string $key, int $status): void
    {
        $actor = in_array($key, ['webhooks.edit', 'webhooks.update', 'webhooks.destroy', 'groups.show', 'groups.edit', 'groups.update', 'groups.destroy'], true)
            ? $this->actors->adminA
            : $this->actors->editorA;

        $response = $this->actingAs($actor)->call($method, $this->path($key), $this->bodyFor($key));

        $this->assertSame(
            $status,
            $response->status(),
            "Expected {$status} for {$method} {$key}, got {$response->status()}"
        );
    }

    public function test_indexes_do_not_list_foreign_org_rows(): void
    {
        $mapA = $this->actors->mapIn($this->actors->orgA);
        $formA = $this->actors->formIn($this->actors->orgA);
        $groupA = $this->actors->groupIn($this->actors->orgA);
        $hookA = $this->actors->webhookIn($this->actors->orgA, 'secret-org-a');

        $this->actingAs($this->actors->editorA)
            ->get(route('maps.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('maps.data')
                ->where('maps.data', fn ($rows) => collect($rows)->every(
                    fn ($row) => (int) data_get($row, 'organization_id') === $this->actors->orgA->id
                        && (int) data_get($row, 'id') !== $this->mapB->id
                )));

        $this->actingAs($this->actors->editorA)
            ->get(route('forms.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('forms.data', fn ($rows) => collect($rows)->every(
                    fn ($row) => (int) data_get($row, 'id') !== $this->formB->id
                )));

        $this->actingAs($this->actors->adminA)
            ->get(route('groups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups.data', fn ($rows) => collect($rows)->contains('id', $groupA->id)
                    && collect($rows)->doesntContain('id', $this->groupB->id)));

        $this->actingAs($this->actors->adminA)
            ->get(route('webhooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('webhooks.data', fn ($rows) => collect($rows)->contains('id', $hookA->id)
                    && collect($rows)->doesntContain('id', $this->webhookB->id)
                    && collect($rows)->every(fn ($row) => ! str_contains((string) data_get($row, 'secret'), 'secret-org-b'))));

        $this->assertNotNull($mapA);
        $this->assertNotNull($formA);
    }

    public function test_webhook_edit_does_not_leak_foreign_secret(): void
    {
        $this->actingAs($this->actors->adminA)
            ->get(route('webhooks.edit', $this->webhookB))
            ->assertForbidden()
            ->assertDontSee('secret-org-b');
    }

    public function test_null_org_user_is_denied_org_resources(): void
    {
        // maps/layers sit outside EnsureUserHasOrganization; policies still deny cross-org.
        $this->actingAs($this->actors->nullOrg)
            ->get(route('maps.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('maps.data', fn ($rows) => collect($rows)->isEmpty()));

        $this->actingAs($this->actors->nullOrg)
            ->get(route('layers.show', $this->layerB))
            ->assertForbidden();

        // Organization-gated console routes redirect/forbid null-org users.
        $this->actingAs($this->actors->nullOrg)
            ->get(route('forms.index'))
            ->assertForbidden();

        $this->actingAs($this->actors->nullOrg)
            ->get(route('webhooks.index'))
            ->assertForbidden();
    }

    protected function path(string $key): string
    {
        return match ($key) {
            'projects.show' => route('projects.show', $this->projectB),
            'layers.show' => route('layers.show', $this->layerB),
            'maps.show' => route('maps.show', $this->mapB),
            'maps.edit' => route('maps.edit', $this->mapB),
            'maps.destroy' => route('maps.destroy', $this->mapB),
            'forms.show' => route('forms.show', $this->formB),
            'forms.edit' => route('forms.edit', $this->formB),
            'dashboards.show' => route('dashboards.show', $this->dashboardB),
            'dashboards.edit' => route('dashboards.edit', $this->dashboardB),
            'dashboards.data' => route('dashboards.data', $this->dashboardB),
            'imports.show' => route('imports.show', $this->importB),
            'imports.destroy' => route('imports.destroy', $this->importB),
            'groups.show' => route('groups.show', $this->groupB),
            'groups.edit' => route('groups.edit', $this->groupB),
            'groups.update' => route('groups.update', $this->groupB),
            'groups.destroy' => route('groups.destroy', $this->groupB),
            'webhooks.edit' => route('webhooks.edit', $this->webhookB),
            'webhooks.update' => route('webhooks.update', $this->webhookB),
            'webhooks.destroy' => route('webhooks.destroy', $this->webhookB),
            'mvt.tiles' => "/api/layers/{$this->layerB->id}/tiles/0/0/0.mvt",
            default => throw new \InvalidArgumentException($key),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyFor(string $key): array
    {
        return match ($key) {
            'groups.update' => ['name' => 'hijack', 'description' => 'nope'],
            'webhooks.update' => [
                'name' => 'hijack',
                'url' => 'https://evil.example.test/hook',
                'events' => ['feature.created'],
                'is_active' => true,
            ],
            default => [],
        };
    }
}
