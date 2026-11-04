<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\AvlUnit;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacuationOrder;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Models\StagingArea;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Support\Enums\Lifeline;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\GeometryReader;
use Lumina\Disaster\Support\PresentsAssetRows;

/**
 * Assembles the Common Operational Picture payload with eager loads (no N+1).
 */
final class CopSnapshotBuilder
{
    public function __construct(
        private readonly int $organizationId,
        private readonly User $user,
        private readonly int $staleAfterSeconds = 30,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $fetchedAt = now()->utc()->toIso8601String();
        $includeAvl = Gate::forUser($this->user)->any(['disaster.manage', 'disaster.telemetry']);

        $incidents = $this->activeIncidents();
        $closures = $this->activeClosures();
        $activations = $this->activeActivations();
        $evacOrders = $this->activeEvacuationOrders();
        $assets = $this->fixedAssets();
        $lifelines = $this->lifelineRollUp();
        $avl = $includeAvl ? $this->avlUnits() : null;

        $sources = [
            'incidents' => $this->sourceMeta($fetchedAt, $incidents->count()),
            'closures' => $this->sourceMeta($fetchedAt, $closures->count()),
            'activations' => $this->sourceMeta($fetchedAt, $activations->count()),
            'evacuation_orders' => $this->sourceMeta($fetchedAt, $evacOrders->count()),
            'assets' => $this->sourceMeta(
                $fetchedAt,
                count($assets['medical']) + count($assets['staging']) + count($assets['critical']),
            ),
            'lifelines' => $this->sourceMeta($fetchedAt, count($lifelines)),
        ];

        if ($includeAvl) {
            $sources['avl'] = $this->sourceMeta($fetchedAt, count($avl['units'] ?? []));
        }

        return [
            'generated_at' => $fetchedAt,
            'poll_interval_ms' => (int) config('disaster.cop.poll_interval_ms', 15_000),
            'stale_after_seconds' => $this->staleAfterSeconds,
            'sources' => $sources,
            'incidents' => $incidents->all(),
            'closures' => $closures->all(),
            'activations' => $activations->all(),
            'evacuation_orders' => $evacOrders->all(),
            'assets' => $assets,
            'avl' => $avl,
            'avl_included' => $includeAvl,
            'lifelines' => $lifelines,
            'map' => $this->mapPanel($incidents, $closures, $assets, $avl),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activeIncidents(): Collection
    {
        return Incident::query()
            ->forOrganization($this->organizationId)
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->limit(100)
            ->get(['id', 'external_id', 'title', 'incident_type', 'priority', 'status', 'opened_at', 'geom'])
            ->map(function (Incident $incident) {
                $row = $incident->only(['id', 'external_id', 'title', 'incident_type', 'priority', 'status', 'opened_at']);
                $coords = GeometryReader::pointCoordinates($incident->geom);
                if ($coords !== null) {
                    $row['latitude'] = $coords['latitude'];
                    $row['longitude'] = $coords['longitude'];
                }

                return $row;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activeClosures(): Collection
    {
        $now = now();

        return Closure::query()
            ->forOrganization($this->organizationId)
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'external_id', 'closure_type', 'severity', 'valid_from', 'valid_to', 'source', 'geom'])
            ->map(function (Closure $closure) {
                $row = $closure->only(['id', 'external_id', 'closure_type', 'severity', 'valid_from', 'valid_to', 'source']);
                $geo = GeometryReader::asGeoJson($closure->geom);
                if ($geo !== null) {
                    $row['geojson'] = $geo;
                }

                return $row;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activeActivations(): Collection
    {
        return Activation::query()
            ->forOrganization($this->organizationId)
            ->whereIn('status', ['proposed', 'active'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'hazard_type', 'severity', 'urgency', 'certainty',
                'status', 'advisory_text', 'is_exercise', 'valid_from', 'valid_to', 'created_at',
            ])
            ->map(fn (Activation $row) => [
                'id' => $row->id,
                'hazard_type' => $row->hazard_type,
                'severity' => $row->severity,
                'urgency' => $row->urgency,
                'certainty' => $row->certainty,
                'status' => $row->status,
                'advisory_text' => $row->advisory_text,
                'is_exercise' => $row->is_exercise,
                'valid_from' => optional($row->valid_from)?->toIso8601String(),
                'valid_to' => optional($row->valid_to)?->toIso8601String(),
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activeEvacuationOrders(): Collection
    {
        return EvacuationOrder::query()
            ->forOrganization($this->organizationId)
            ->whereIn('status', [
                EvacuationOrderStatus::Issued->value,
                EvacuationOrderStatus::InProgress->value,
            ])
            ->orderByDesc('issued_at')
            ->limit(50)
            ->get([
                'id', 'text_en', 'text_ar', 'status', 'estimated_population', 'evacuated_count',
                'unaccounted_count', 'issued_at', 'expires_at', 'is_official',
            ])
            ->map(fn (EvacuationOrder $order) => [
                'id' => $order->id,
                // Arabic UI shows the Arabic order text first; English is the fallback (and vice versa).
                'title' => (app()->getLocale() === 'ar'
                    ? ($order->text_ar ?: $order->text_en)
                    : ($order->text_en ?: $order->text_ar))
                    ?: __('disaster.evac_order_number', ['id' => $order->id]),
                'text_en' => $order->text_en,
                'text_ar' => $order->text_ar,
                'status' => $order->status?->value ?? $order->status,
                'estimated_population' => $order->estimated_population,
                'evacuated_count' => $order->evacuated_count,
                'unaccounted_count' => $order->unaccounted_count,
                'issued_at' => optional($order->issued_at)?->toIso8601String(),
                'expires_at' => optional($order->expires_at)?->toIso8601String(),
                'is_official' => $order->is_official,
            ]);
    }

    /**
     * @return array{medical: list<array<string, mixed>>, staging: list<array<string, mixed>>, critical: list<array<string, mixed>>}
     */
    private function fixedAssets(): array
    {
        $medicalFields = ['id', 'facility_id', 'name', 'resource_status', 'generator_status', 'available_beds', 'bed_capacity', 'geom'];
        $stagingFields = ['id', 'staging_id', 'name', 'resource_status', 'max_vehicle_cap', 'geom'];
        $criticalFields = ['id', 'site_id', 'type', 'name', 'capacity', 'geom'];

        return [
            'medical' => MedicalFacility::query()
                ->forOrganization($this->organizationId)
                ->orderBy('name')
                ->limit(200)
                ->get($medicalFields)
                ->map(fn (MedicalFacility $row) => PresentsAssetRows::row($row, array_diff($medicalFields, ['geom'])))
                ->all(),
            'staging' => StagingArea::query()
                ->forOrganization($this->organizationId)
                ->orderBy('name')
                ->limit(200)
                ->get($stagingFields)
                ->map(fn (StagingArea $row) => PresentsAssetRows::row($row, array_diff($stagingFields, ['geom'])))
                ->all(),
            'critical' => CriticalSite::query()
                ->forOrganization($this->organizationId)
                ->orderBy('name')
                ->limit(200)
                ->get($criticalFields)
                ->map(fn (CriticalSite $row) => PresentsAssetRows::row($row, array_diff($criticalFields, ['geom'])))
                ->all(),
        ];
    }

    /**
     * @return array{units: list<array<string, mixed>>, by_class: array<string, int>, by_status: array<string, int>}
     */
    private function avlUnits(): array
    {
        $units = AvlUnit::query()
            ->forOrganization($this->organizationId)
            ->orderByDesc('last_ping_at')
            ->limit(500)
            ->get(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at', 'speed_kmh', 'heading_deg', 'geom'])
            ->map(function (AvlUnit $unit) {
                $row = $unit->only(['id', 'unit_id', 'resource_class', 'resource_status', 'last_ping_at', 'speed_kmh', 'heading_deg']);
                $coords = GeometryReader::pointCoordinates($unit->geom);
                if ($coords !== null) {
                    $row['latitude'] = $coords['latitude'];
                    $row['longitude'] = $coords['longitude'];
                }

                return $row;
            })
            ->all();

        $byClass = [];
        $byStatus = [];
        foreach ($units as $unit) {
            $class = (string) ($unit['resource_class'] ?? 'unknown');
            $status = (string) ($unit['resource_status'] ?? 'unknown');
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return [
            'units' => $units,
            'by_class' => $byClass,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lifelineRollUp(): array
    {
        /** @var Collection<int, LifelineComponent> $components */
        $components = LifelineComponent::query()
            ->forOrganization($this->organizationId)
            // Eager-load full latestStatus (avoid column constraints with latestOfMany on SQLite).
            ->with('latestStatus')
            ->orderBy('lifeline')
            ->orderBy('name')
            ->get(['id', 'organization_id', 'lifeline', 'name', 'linked_asset_type', 'linked_asset_id', 'layer_id', 'layer_feature_id']);

        $grouped = $components->groupBy(fn (LifelineComponent $c) => $c->lifeline?->value ?? (string) $c->lifeline);

        $rows = [];
        foreach (Lifeline::cases() as $lifeline) {
            /** @var Collection<int, LifelineComponent> $group */
            $group = $grouped->get($lifeline->value, collect());
            $componentStatuses = [];
            $componentRows = [];

            foreach ($group as $component) {
                $latest = $component->latestStatus;
                $status = $latest?->status ?? LifelineComponentStatus::Unknown;
                $componentStatuses[] = $status;
                $componentRows[] = [
                    'id' => $component->id,
                    'name' => $component->name,
                    'linked_asset_type' => $component->linked_asset_type,
                    'linked_asset_id' => $component->linked_asset_id,
                    'layer_id' => $component->layer_id,
                    'layer_feature_id' => $component->layer_feature_id,
                    'status' => $status->value,
                    'color' => $status->color(),
                    'reason' => $latest?->reason,
                    'reported_at' => optional($latest?->reported_at)?->toIso8601String(),
                ];
            }

            $rollup = LifelineComponentStatus::rollUp($componentStatuses);

            $rows[] = [
                'lifeline' => $lifeline->value,
                'label' => $lifeline->label(),
                'status' => $rollup->value,
                'color' => $rollup->color(),
                'component_count' => count($componentRows),
                'components' => $componentRows,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $incidents
     * @param  Collection<int, array<string, mixed>>  $closures
     * @param  array{medical: list<array<string, mixed>>, staging: list<array<string, mixed>>, critical: list<array<string, mixed>>}  $assets
     * @param  array{units: list<array<string, mixed>>, by_class: array<string, int>, by_status: array<string, int>}|null  $avl
     * @return array<string, mixed>
     */
    private function mapPanel(Collection $incidents, Collection $closures, array $assets, ?array $avl): array
    {
        $points = [];

        foreach ($incidents as $incident) {
            if (isset($incident['latitude'], $incident['longitude'])) {
                $points[] = [
                    'kind' => 'incident',
                    'id' => $incident['id'],
                    'label' => $incident['title'] ?? $incident['external_id'],
                    'latitude' => $incident['latitude'],
                    'longitude' => $incident['longitude'],
                ];
            }
        }

        foreach (['medical', 'staging', 'critical'] as $bucket) {
            foreach ($assets[$bucket] as $asset) {
                if (isset($asset['latitude'], $asset['longitude'])) {
                    $points[] = [
                        'kind' => $bucket,
                        'id' => $asset['id'],
                        'label' => $asset['name'] ?? (string) $asset['id'],
                        'latitude' => $asset['latitude'],
                        'longitude' => $asset['longitude'],
                    ];
                }
            }
        }

        if ($avl !== null) {
            foreach ($avl['units'] as $unit) {
                if (isset($unit['latitude'], $unit['longitude'])) {
                    $points[] = [
                        'kind' => 'avl',
                        'id' => $unit['id'],
                        'label' => $unit['unit_id'],
                        'latitude' => $unit['latitude'],
                        'longitude' => $unit['longitude'],
                        'resource_class' => $unit['resource_class'] ?? null,
                        'resource_status' => $unit['resource_status'] ?? null,
                    ];
                }
            }
        }

        return [
            'points' => $points,
            'closures' => $closures->map(fn (array $c) => [
                'id' => $c['id'],
                'external_id' => $c['external_id'] ?? null,
                'closure_type' => $c['closure_type'] ?? null,
                'severity' => $c['severity'] ?? null,
                'geojson' => $c['geojson'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{fetched_at: string, stale_after_seconds: int, count: int}
     */
    private function sourceMeta(string $fetchedAt, int $count): array
    {
        return [
            'fetched_at' => $fetchedAt,
            'stale_after_seconds' => $this->staleAfterSeconds,
            'count' => $count,
        ];
    }
}
