<?php

namespace App\Http\Controllers;

use App\Models\DashboardBoard;
use App\Models\Form;
use App\Models\Map;
use App\Models\Organization;
use App\Models\SolutionPackDefinition;
use App\Services\SolutionPackInstaller;
use App\SolutionPacks\SolutionPack;
use App\SolutionPacks\StoredPack;
use App\SolutionPacks\WorkflowResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AppGalleryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Destination page: installed pack workspaces, public share links, recent apps.
     */
    public function index(Request $request)
    {
        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $installer = app(SolutionPackInstaller::class);

        return Inertia::render('Apps/Index', [
            'workspaces' => $this->workspaces($organization, $installer),
            'shared' => $this->shared($organization),
            'recent' => $this->recent($organization),
        ]);
    }

    /**
     * Installed packs with links to their workspace artifacts and runbook.
     *
     * @return list<array{slug: string, name: string, description: string, contents: string, items: list<array<string, mixed>>, workflow: list<array<string, mixed>>}>
     */
    protected function workspaces(Organization $organization, SolutionPackInstaller $installer): array
    {
        $packs = array_merge(
            SolutionPack::all(),
            SolutionPackDefinition::query()
                ->where('organization_id', $organization->id)
                ->orderBy('name')
                ->get()
                ->map(fn ($model) => StoredPack::fromModel($model))
                ->all()
        );

        $workspaces = [];

        foreach ($packs as $pack) {
            if (! $installer->isInstalled($pack, $organization)) {
                continue;
            }

            $definition = $pack->definition();
            $orgId = $organization->id;

            $items = [];

            $maps = Map::where('organization_id', $orgId)
                ->whereIn('name', collect($definition['maps'] ?? [])->pluck('name'))
                ->orderBy('name')
                ->get();
            foreach ($maps as $map) {
                $items[] = [
                    'type' => 'map',
                    'name' => $map->name,
                    'href' => '/maps/'.$map->id,
                    'detail' => 'Office',
                ];
            }

            $forms = Form::where('organization_id', $orgId)
                ->whereIn('name', collect($definition['forms'] ?? [])->pluck('name'))
                ->orderBy('name')
                ->get();
            foreach ($forms as $form) {
                $items[] = [
                    'type' => 'form',
                    'name' => $form->name,
                    'href' => $form->is_public && $form->share_token ? '/f/'.$form->share_token : '/forms/'.$form->id,
                    'detail' => $form->is_public ? 'Field · public link' : 'Field',
                ];
            }

            $dashboards = DashboardBoard::where('organization_id', $orgId)
                ->whereIn('name', collect($definition['dashboards'] ?? [])->pluck('name'))
                ->orderBy('name')
                ->get();
            foreach ($dashboards as $dashboard) {
                $items[] = [
                    'type' => 'dashboard',
                    'name' => $dashboard->name,
                    'href' => '/dashboards/'.$dashboard->id,
                    'detail' => 'Office',
                ];
            }

            $workspaces[] = [
                'slug' => $pack->slug(),
                'name' => $pack->displayName(),
                'description' => $pack->displayDescription(),
                'contents' => $pack->contentsSummary(),
                'items' => $items,
                'workflow' => app(WorkflowResolver::class)->resolve($pack, $organization),
            ];
        }

        return $workspaces;
    }

    /**
     * Org content with an active public share link.
     *
     * @return list<array<string, mixed>>
     */
    protected function shared(Organization $organization): array
    {
        $orgId = $organization->id;
        $items = [];

        foreach (Map::where('organization_id', $orgId)->where('is_public', true)->whereNotNull('share_token')->orderByDesc('updated_at')->take(6)->get() as $map) {
            $items[] = [
                'type' => 'map',
                'name' => $map->name,
                'href' => '/maps/shared/'.$map->share_token,
                'detail' => 'Shared map',
            ];
        }

        foreach (DashboardBoard::where('organization_id', $orgId)->where('is_public', true)->whereNotNull('share_token')->orderByDesc('updated_at')->take(6)->get() as $dashboard) {
            $items[] = [
                'type' => 'dashboard',
                'name' => $dashboard->name,
                'href' => '/dashboards/shared/'.$dashboard->share_token,
                'detail' => 'Shared dashboard',
            ];
        }

        foreach (Form::where('organization_id', $orgId)->where('is_public', true)->whereNotNull('share_token')->orderByDesc('updated_at')->take(6)->get() as $form) {
            $items[] = [
                'type' => 'form',
                'name' => $form->name,
                'href' => '/f/'.$form->share_token,
                'detail' => 'Public form',
            ];
        }

        return $items;
    }

    /**
     * Latest maps, dashboards, and forms regardless of share state.
     *
     * @return list<array<string, mixed>>
     */
    protected function recent(Organization $organization): array
    {
        $orgId = $organization->id;
        $rows = [];

        foreach (Map::where('organization_id', $orgId)->orderByDesc('updated_at')->take(4)->get() as $map) {
            $rows[] = ['type' => 'map', 'name' => $map->name, 'href' => '/maps/'.$map->id, 'updated_at' => $map->updated_at];
        }
        foreach (DashboardBoard::where('organization_id', $orgId)->orderByDesc('updated_at')->take(4)->get() as $dashboard) {
            $rows[] = ['type' => 'dashboard', 'name' => $dashboard->name, 'href' => '/dashboards/'.$dashboard->id, 'updated_at' => $dashboard->updated_at];
        }
        foreach (Form::where('organization_id', $orgId)->orderByDesc('updated_at')->take(4)->get() as $form) {
            $rows[] = ['type' => 'form', 'name' => $form->name, 'href' => '/forms/'.$form->id, 'updated_at' => $form->updated_at];
        }

        usort($rows, fn ($a, $b) => $b['updated_at'] <=> $a['updated_at']);

        return collect(array_slice($rows, 0, 8))
            ->map(fn (array $row) => [
                'type' => $row['type'],
                'name' => $row['name'],
                'href' => $row['href'],
                'detail' => optional($row['updated_at'])->diffForHumans(),
            ])
            ->values()
            ->all();
    }
}
