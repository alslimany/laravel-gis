<?php

namespace Tests\TestHelpers;

use App\Models\DashboardBoard;
use App\Models\DataImport;
use App\Models\Form;
use App\Models\Group;
use App\Models\Layer;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use Laravel\Sanctum\Sanctum;

/**
 * Shared two-org fixtures for DG-0.5 security regression tests.
 */
final class SecurityActors
{
    public Role $admin;

    public Role $editor;

    public Role $viewer;

    public Organization $orgA;

    public Organization $orgB;

    public User $adminA;

    public User $editorA;

    public User $viewerA;

    public User $adminB;

    public User $editorB;

    public User $nullOrg;

    public static function create(): self
    {
        $actors = new self;

        $actors->admin = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $actors->editor = Role::create(['name' => 'editor', 'description' => 'Editor']);
        $actors->viewer = Role::create(['name' => 'viewer', 'description' => 'Viewer']);

        $actors->orgA = Organization::factory()->create(['name' => 'Security Org A']);
        $actors->orgB = Organization::factory()->create(['name' => 'Security Org B']);

        $actors->adminA = $actors->userIn($actors->orgA, $actors->admin);
        $actors->editorA = $actors->userIn($actors->orgA, $actors->editor);
        $actors->viewerA = $actors->userIn($actors->orgA, $actors->viewer);
        $actors->adminB = $actors->userIn($actors->orgB, $actors->admin);
        $actors->editorB = $actors->userIn($actors->orgB, $actors->editor);
        $actors->nullOrg = User::factory()->create(['organization_id' => null]);

        return $actors;
    }

    public function userIn(Organization $org, Role $role): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->roles()->attach($role, ['organization_id' => $org->id]);

        return $user->fresh();
    }

    public function projectIn(Organization $org, ?User $owner = null): Project
    {
        return Project::factory()->create([
            'organization_id' => $org->id,
            'user_id' => ($owner ?? $this->ownerFor($org))->id,
        ]);
    }

    public function layerIn(Organization $org, ?User $owner = null, ?Project $project = null): Layer
    {
        $owner ??= $this->ownerFor($org);
        $project ??= $this->projectIn($org, $owner);

        return Layer::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'project_id' => $project->id,
            'published' => false,
        ]);
    }

    public function mapIn(Organization $org, ?User $owner = null, bool $public = false): Map
    {
        $owner ??= $this->ownerFor($org);

        return Map::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'is_public' => $public,
            'name' => 'Map '.$org->name,
        ]);
    }

    public function formIn(Organization $org, ?User $owner = null, ?Layer $layer = null): Form
    {
        $owner ??= $this->ownerFor($org);
        $layer ??= $this->layerIn($org, $owner);

        return Form::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'layer_id' => $layer->id,
            'name' => 'Form '.$org->name,
            'description' => 'DG-0.5 fixture',
            'schema' => ['fields' => []],
            'is_public' => false,
            'collect_geometry' => false,
        ]);
    }

    public function dashboardIn(Organization $org, ?User $owner = null): DashboardBoard
    {
        $owner ??= $this->ownerFor($org);

        return DashboardBoard::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'name' => 'Dashboard '.$org->name,
            'description' => 'DG-0.5 fixture',
            'widgets' => [],
            'is_public' => false,
        ]);
    }

    public function webhookIn(Organization $org, string $secret = 'org-secret'): Webhook
    {
        return Webhook::create([
            'organization_id' => $org->id,
            'name' => 'Hook '.$org->name,
            'url' => 'https://hooks.example.test/'.strtolower(str_replace(' ', '-', $org->name)),
            'events' => ['feature.created'],
            'secret' => $secret,
            'is_active' => true,
        ]);
    }

    public function groupIn(Organization $org): Group
    {
        return Group::create([
            'organization_id' => $org->id,
            'name' => 'Group '.$org->name,
            'description' => 'DG-0.5 fixture',
        ]);
    }

    public function importIn(Organization $org, ?User $owner = null): DataImport
    {
        $owner ??= $this->ownerFor($org);

        return DataImport::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'status' => 'completed',
        ]);
    }

    /**
     * @param  list<string>  $abilities
     */
    public function actingSanctum(User $user, array $abilities = ['*']): void
    {
        Sanctum::actingAs($user, $abilities);
    }

    public function dcLayerAbility(Layer $layer, string $scope = 'read'): string
    {
        return 'dc:layer:'.$layer->public_id.':'.$scope;
    }

    protected function ownerFor(Organization $org): User
    {
        return $org->id === $this->orgA->id ? $this->editorA : $this->editorB;
    }
}
