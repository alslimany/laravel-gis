<?php

namespace App\SolutionPacks;

use App\Models\DashboardBoard;
use App\Models\Form;
use App\Models\Map;
use App\Models\Organization;

/**
 * Resolves a pack's declarative workflow steps against the artifacts that
 * actually exist in an organization. Missing targets are reported as
 * unresolved (href null) so callers can render them as disabled steps.
 */
class WorkflowResolver
{
    public const KINDS = ['form', 'dashboard', 'map', 'text'];

    /**
     * @return list<array{title: string, description: string, kind: string, target: ?string, href: ?string}>
     */
    public function resolve(SolutionPack $pack, Organization $organization): array
    {
        $steps = $pack->workflow();
        if ($steps === []) {
            return [];
        }

        $orgId = $organization->id;

        $targets = ['form' => [], 'dashboard' => [], 'map' => []];
        foreach ($steps as $step) {
            $kind = (string) ($step['kind'] ?? 'text');
            $target = trim((string) ($step['target'] ?? ''));
            if ($target !== '' && isset($targets[$kind])) {
                $targets[$kind][] = $target;
            }
        }

        $forms = $this->byName(Form::query()->where('organization_id', $orgId), $targets['form']);
        $dashboards = $this->byName(DashboardBoard::query()->where('organization_id', $orgId), $targets['dashboard']);
        $maps = $this->byName(Map::query()->where('organization_id', $orgId), $targets['map']);

        return collect($steps)
            ->map(function (array $step) use ($forms, $dashboards, $maps) {
                $kind = (string) ($step['kind'] ?? 'text');
                $target = trim((string) ($step['target'] ?? '')) ?: null;
                $href = null;

                if ($target !== null) {
                    $href = match ($kind) {
                        'form' => isset($forms[$target])
                            ? ($forms[$target]->is_public && $forms[$target]->share_token
                                ? '/f/'.$forms[$target]->share_token
                                : '/forms/'.$forms[$target]->id)
                            : null,
                        'dashboard' => isset($dashboards[$target]) ? '/dashboards/'.$dashboards[$target]->id : null,
                        'map' => isset($maps[$target]) ? '/maps/'.$maps[$target]->id : null,
                        default => null,
                    };
                }

                return [
                    'title' => trim((string) ($step['title'] ?? '')),
                    'description' => trim((string) ($step['description'] ?? '')),
                    'kind' => $kind,
                    'target' => $target,
                    'href' => $href,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $names
     * @return array<string, object>
     */
    protected function byName($query, array $names): array
    {
        if ($names === []) {
            return [];
        }

        return $query->whereIn('name', array_unique($names))
            ->get()
            ->keyBy('name')
            ->all();
    }
}
