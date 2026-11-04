<?php

namespace App\SolutionPacks;

use App\Models\Organization;
use App\Models\SolutionPackDefinition;
use App\Services\SolutionPackInstaller;

/**
 * Builds the pack listing used by organization settings and the builder.
 */
class PackCatalog
{
    /**
     * @return list<array{
     *     id: int|null,
     *     slug: string,
     *     name: string,
     *     description: string,
     *     contents: string,
     *     installed: bool,
     *     custom: bool,
     *     unpublished: int,
     *     workflow: list<array{title: string, description: string, kind: string, target: ?string, href: ?string}>,
     * }>
     */
    public function list(Organization $organization): array
    {
        $installer = app(SolutionPackInstaller::class);
        $packs = [];

        foreach (SolutionPack::all() as $pack) {
            $packs[] = $this->props($pack, null, $organization, $installer);
        }

        $stored = SolutionPackDefinition::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get();

        foreach ($stored as $model) {
            $packs[] = $this->props(StoredPack::fromModel($model), $model, $organization, $installer);
        }

        return $packs;
    }

    /**
     * @return array{id: int|null, slug: string, name: string, description: string, contents: string, installed: bool, custom: bool, unpublished: int, workflow: list<array{title: string, description: string, kind: string, target: ?string, href: ?string}>}
     */
    protected function props(SolutionPack $pack, ?SolutionPackDefinition $model, Organization $organization, SolutionPackInstaller $installer): array
    {
        $installed = $installer->isInstalled($pack, $organization);
        $unpublished = 0;

        if ($installed) {
            $unpublished = collect($installer->packLayers($pack, $organization))
                ->where('published', false)
                ->count();
        }

        return [
            'id' => $model?->id,
            'slug' => $pack->slug(),
            'name' => $pack->displayName(),
            'description' => $pack->displayDescription(),
            'contents' => $pack->contentsSummary(),
            'installed' => $installed,
            'custom' => $model !== null,
            'unpublished' => $unpublished,
            'workflow' => $workflow = app(WorkflowResolver::class)->resolve($pack, $organization),
            'workflow_label' => trans_choice('packs.workflow_steps', count($workflow)),
        ];
    }
}
