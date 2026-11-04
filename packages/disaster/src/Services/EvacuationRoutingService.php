<?php

namespace Lumina\Disaster\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lumina\Disaster\Contracts\RoutingEngine;
use Lumina\Disaster\Models\Closure;
use Lumina\Disaster\Models\CriticalSite;
use Lumina\Disaster\Models\EvacAssignment;
use Lumina\Disaster\Models\EvacCorridor;
use Lumina\Disaster\Models\RoadNetworkEdge;
use Lumina\Disaster\Models\RoutingRun;
use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\GeometryWriter;

class EvacuationRoutingService
{
    public function __construct(
        private readonly RoutingEngine $engine,
    ) {}

    /**
     * Apply active closures to the road network, then recompute zone→shelter routes
     * and capacity-aware assignments.
     *
     * @param  list<array{
     *     zone_key: string,
     *     population: int,
     *     lat: float,
     *     lon: float,
     *     vulnerable?: bool,
     *     priority?: int
     * }>  $zones
     */
    public function recompute(
        int $organizationId,
        ?int $evacuationOrderId = null,
        array $zones = [],
        ?int $createdBy = null,
    ): RoutingRun {
        $started = microtime(true);

        $run = RoutingRun::query()->create([
            'organization_id' => $organizationId,
            'evacuation_order_id' => $evacuationOrderId,
            'status' => 'running',
            'metrics' => [],
            'created_at' => now(),
        ]);

        try {
            $blockedCount = $this->applyActiveClosures($organizationId);
            $zones = $zones !== [] ? $zones : $this->defaultZonesFromVulnerableSites($organizationId);
            $zones = $this->prioritizeZones($organizationId, $zones);

            $edges = $this->loadEdgesForEngine($organizationId);
            $shelters = $this->loadShelters($organizationId);
            $remaining = [];
            foreach ($shelters as $shelter) {
                $remaining[$shelter->id] = $shelter->capacity !== null
                    ? (int) $shelter->capacity
                    : PHP_INT_MAX;
            }

            // Drop previous computed assignments for this order (or org-wide null order).
            EvacAssignment::query()
                ->forOrganization($organizationId)
                ->where('evacuation_order_id', $evacuationOrderId)
                ->whereNull('override_reason')
                ->delete();

            EvacCorridor::query()
                ->forOrganization($organizationId)
                ->where('source', 'computed')
                ->where('evacuation_order_id', $evacuationOrderId)
                ->delete();

            $assigned = 0;
            $skipped = 0;
            $routes = [];

            foreach ($zones as $zone) {
                $population = max(0, (int) $zone['population']);
                if ($population === 0) {
                    $skipped++;

                    continue;
                }

                $shelter = $this->pickShelter($shelters, $remaining, $population, $zone);
                if ($shelter === null) {
                    $skipped++;

                    continue;
                }

                $route = $this->engine->route(
                    $edges,
                    ['lat' => (float) $zone['lat'], 'lon' => (float) $zone['lon']],
                    $this->sitePoint($shelter),
                );

                if ($route === null) {
                    $skipped++;

                    continue;
                }

                $corridor = EvacCorridor::query()->create([
                    'organization_id' => $organizationId,
                    'name' => 'Computed '.$zone['zone_key'].' → '.$shelter->site_id,
                    'source' => 'computed',
                    'shelter_site_id' => $shelter->id,
                    'distance_m' => $route['distance_m'],
                    'travel_time_s' => $route['travel_time_s'],
                    'evacuation_order_id' => $evacuationOrderId,
                    'computed_at' => now(),
                    'props' => [
                        'zone_key' => $zone['zone_key'],
                        'edge_ids' => $route['edge_ids'],
                    ],
                    'geom' => GeometryWriter::lineFromGeoJson([
                        'type' => 'LineString',
                        'coordinates' => $route['coordinates'],
                    ]),
                ]);

                EvacAssignment::query()->create([
                    'organization_id' => $organizationId,
                    'evacuation_order_id' => $evacuationOrderId,
                    'zone_key' => $zone['zone_key'],
                    'shelter_site_id' => $shelter->id,
                    'population' => $population,
                    'corridor_id' => $corridor->id,
                    'override_reason' => null,
                    'created_by' => $createdBy,
                ]);

                if (($remaining[$shelter->id] ?? PHP_INT_MAX) !== PHP_INT_MAX) {
                    $remaining[$shelter->id] -= $population;
                }

                $assigned++;
                $routes[] = [
                    'zone_key' => $zone['zone_key'],
                    'shelter_site_id' => $shelter->id,
                    'distance_m' => $route['distance_m'],
                    'travel_time_s' => $route['travel_time_s'],
                ];
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $targetSeconds = (int) config('disaster.routing.city_scale_batch_target_seconds', 30);

            $run->status = 'completed';
            $run->metrics = [
                'duration_ms' => $durationMs,
                'blocked_edges' => $blockedCount,
                'zones' => count($zones),
                'assigned' => $assigned,
                'skipped' => $skipped,
                'routes' => $routes,
                'target_seconds' => $targetSeconds,
                'within_target' => ($durationMs / 1000) <= $targetSeconds,
                'engine' => $this->engine::class,
            ];
            $run->save();

            return $run->fresh();
        } catch (\Throwable $e) {
            $run->status = 'failed';
            $run->metrics = [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ];
            $run->save();

            throw $e;
        }
    }

    /**
     * Mark road edges blocked when they intersect (pgsql) or share external_id / props
     * with an active closure. Returns count of edges marked blocked this pass.
     */
    public function applyActiveClosures(int $organizationId): int
    {
        RoadNetworkEdge::query()
            ->forOrganization($organizationId)
            ->update(['is_blocked' => false]);

        $closures = Closure::query()
            ->forOrganization($organizationId)
            ->where(function ($q) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', now());
            })
            ->get();

        if ($closures->isEmpty()) {
            return 0;
        }

        $blockedIds = [];

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ($closures as $closure) {
                if ($closure->geom === null) {
                    continue;
                }
                $ids = DB::table('dm_road_network_edges')
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('geom')
                    ->whereRaw('ST_Intersects(geom, (SELECT geom FROM dm_closures WHERE id = ?))', [$closure->id])
                    ->pluck('id')
                    ->all();
                $blockedIds = array_merge($blockedIds, $ids);
            }
        }

        $closureExternalIds = $closures->pluck('external_id')->filter()->all();
        if ($closureExternalIds !== []) {
            $matched = RoadNetworkEdge::query()
                ->forOrganization($organizationId)
                ->whereIn('external_id', $closureExternalIds)
                ->pluck('id')
                ->all();
            $blockedIds = array_merge($blockedIds, $matched);
        }

        foreach ($closures as $closure) {
            // Match edges tagged with the closure external id in props.
            RoadNetworkEdge::query()
                ->forOrganization($organizationId)
                ->where('props->closure_external_id', $closure->external_id)
                ->pluck('id')
                ->each(function ($id) use (&$blockedIds) {
                    $blockedIds[] = $id;
                });
        }

        $blockedIds = array_values(array_unique(array_map('intval', $blockedIds)));
        if ($blockedIds === []) {
            return 0;
        }

        RoadNetworkEdge::query()
            ->forOrganization($organizationId)
            ->whereIn('id', $blockedIds)
            ->update(['is_blocked' => true]);

        return count($blockedIds);
    }

    /**
     * Manually reassign a zone to a shelter, recording a reason when capacity is exceeded.
     *
     * @throws ValidationException
     */
    public function overrideAssignment(
        int $organizationId,
        string $zoneKey,
        int $shelterSiteId,
        int $population,
        ?string $overrideReason = null,
        ?int $evacuationOrderId = null,
        ?int $createdBy = null,
        ?int $corridorId = null,
    ): EvacAssignment {
        $shelter = CriticalSite::query()
            ->forOrganization($organizationId)
            ->whereKey($shelterSiteId)
            ->firstOrFail();

        $existingLoad = (int) EvacAssignment::query()
            ->forOrganization($organizationId)
            ->where('shelter_site_id', $shelterSiteId)
            ->when(
                $evacuationOrderId === null,
                fn ($q) => $q->whereNull('evacuation_order_id'),
                fn ($q) => $q->where('evacuation_order_id', $evacuationOrderId),
            )
            ->where('zone_key', '!=', $zoneKey)
            ->sum('population');

        $projected = $existingLoad + max(0, $population);
        $capacity = $shelter->capacity;

        if ($capacity !== null && $projected > (int) $capacity) {
            $reason = trim((string) $overrideReason);
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'override_reason' => 'Shelter capacity would be exceeded; provide override_reason.',
                    'population' => "Projected load {$projected} exceeds capacity {$capacity}.",
                ]);
            }
        } else {
            $overrideReason = $overrideReason !== null && trim($overrideReason) !== ''
                ? trim($overrideReason)
                : null;
        }

        return EvacAssignment::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'evacuation_order_id' => $evacuationOrderId,
                'zone_key' => $zoneKey,
            ],
            [
                'shelter_site_id' => $shelterSiteId,
                'population' => max(0, $population),
                'corridor_id' => $corridorId,
                'override_reason' => $overrideReason !== null ? trim((string) $overrideReason) : null,
                'created_by' => $createdBy,
            ],
        );
    }

    /**
     * Capacity rule helper for callers/tests.
     */
    public function shelterLoad(int $organizationId, int $shelterSiteId, ?int $evacuationOrderId = null): int
    {
        return (int) EvacAssignment::query()
            ->forOrganization($organizationId)
            ->where('shelter_site_id', $shelterSiteId)
            ->when(
                $evacuationOrderId === null,
                fn ($q) => $q->whereNull('evacuation_order_id'),
                fn ($q) => $q->where('evacuation_order_id', $evacuationOrderId),
            )
            ->sum('population');
    }

    /**
     * @return list<array{zone_key: string, population: int, lat: float, lon: float, vulnerable: bool, priority: int}>
     */
    private function defaultZonesFromVulnerableSites(int $organizationId): array
    {
        $vulnerableTypes = config('disaster.routing.vulnerable_site_types', [
            CriticalSiteType::HospitalField->value,
            'school',
            'elderly_care',
        ]);

        $sites = CriticalSite::query()
            ->forOrganization($organizationId)
            ->where(function ($q) use ($vulnerableTypes) {
                $q->whereIn('type', $vulnerableTypes)
                    ->orWhere('type', CriticalSiteType::Shelter->value);
            })
            ->get();

        $zones = [];
        foreach ($sites as $site) {
            if ($site->type === CriticalSiteType::Shelter->value) {
                continue;
            }
            $point = $this->sitePoint($site);
            $zones[] = [
                'zone_key' => 'site:'.$site->site_id,
                'population' => max(1, (int) ($site->capacity ?? 50)),
                'lat' => $point['lat'],
                'lon' => $point['lon'],
                'vulnerable' => true,
                'priority' => (int) config('disaster.routing.vulnerable_priority_boost', 1000),
            ];
        }

        return $zones;
    }

    /**
     * @param  list<array<string, mixed>>  $zones
     * @return list<array<string, mixed>>
     */
    private function prioritizeZones(int $organizationId, array $zones): array
    {
        $boost = (int) config('disaster.routing.vulnerable_priority_boost', 1000);
        $vulnerableTypes = config('disaster.routing.vulnerable_site_types', [
            CriticalSiteType::HospitalField->value,
            'school',
            'elderly_care',
        ]);

        $nearVulnerable = CriticalSite::query()
            ->forOrganization($organizationId)
            ->whereIn('type', $vulnerableTypes)
            ->get();

        foreach ($zones as &$zone) {
            $priority = (int) ($zone['priority'] ?? 0);
            if (! empty($zone['vulnerable'])) {
                $priority += $boost;
            }
            foreach ($nearVulnerable as $site) {
                $point = $this->sitePoint($site);
                $d = $this->haversineM(
                    (float) $zone['lat'],
                    (float) $zone['lon'],
                    $point['lat'],
                    $point['lon'],
                );
                if ($d <= (float) config('disaster.routing.vulnerable_proximity_m', 500)) {
                    $priority += $boost;
                    $zone['vulnerable'] = true;
                    break;
                }
            }
            $zone['priority'] = $priority;
        }
        unset($zone);

        usort($zones, static function (array $a, array $b): int {
            $p = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
            if ($p !== 0) {
                return $p;
            }

            return ((int) ($b['population'] ?? 0)) <=> ((int) ($a['population'] ?? 0));
        });

        return $zones;
    }

    /**
     * @return list<CriticalSite>
     */
    private function loadShelters(int $organizationId): array
    {
        return CriticalSite::query()
            ->forOrganization($organizationId)
            ->where('type', CriticalSiteType::Shelter->value)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  list<CriticalSite>  $shelters
     * @param  array<int, int>  $remaining
     * @param  array<string, mixed>  $zone
     */
    private function pickShelter(array $shelters, array $remaining, int $population, array $zone): ?CriticalSite
    {
        $candidates = [];
        foreach ($shelters as $shelter) {
            $left = $remaining[$shelter->id] ?? PHP_INT_MAX;
            if ($left < $population) {
                continue;
            }
            $point = $this->sitePoint($shelter);
            $distance = $this->haversineM(
                (float) $zone['lat'],
                (float) $zone['lon'],
                $point['lat'],
                $point['lon'],
            );
            $candidates[] = ['shelter' => $shelter, 'distance' => $distance];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return $candidates[0]['shelter'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEdgesForEngine(int $organizationId): array
    {
        return RoadNetworkEdge::query()
            ->forOrganization($organizationId)
            ->orderBy('id')
            ->get()
            ->map(function (RoadNetworkEdge $edge) {
                $coordinates = data_get($edge->props, 'coordinates');
                if (! is_array($coordinates) && is_string($edge->geom)) {
                    $coordinates = $this->lineStringCoordinatesFromWkt($edge->geom);
                }

                return [
                    'id' => $edge->id,
                    'from_node' => $edge->from_node,
                    'to_node' => $edge->to_node,
                    'length_m' => (float) $edge->length_m,
                    'free_flow_s' => (int) $edge->free_flow_s,
                    'is_blocked' => (bool) $edge->is_blocked,
                    'coordinates' => is_array($coordinates) ? $coordinates : null,
                ];
            })
            ->all();
    }

    /**
     * @return array{lat: float, lon: float}
     */
    private function sitePoint(CriticalSite $site): array
    {
        $lat = data_get($site->getAttributes(), 'props.lat');
        $lon = data_get($site->getAttributes(), 'props.lon');
        // CriticalSite has no props; parse geom WKT on sqlite or use defaults.
        if (is_string($site->geom) && str_starts_with(strtoupper(trim($site->geom)), 'POINT')) {
            if (preg_match('/POINT\s*\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i', $site->geom, $m)) {
                return ['lon' => (float) $m[1], 'lat' => (float) $m[2]];
            }
        }

        return [
            'lat' => (float) ($lat ?? 0),
            'lon' => (float) ($lon ?? 0),
        ];
    }

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    private function lineStringCoordinatesFromWkt(string $wkt): ?array
    {
        if (! preg_match('/LINESTRING\s*\((.+)\)/i', $wkt, $m)) {
            return null;
        }

        $coords = [];
        foreach (explode(',', $m[1]) as $pair) {
            $parts = preg_split('/\s+/', trim($pair)) ?: [];
            if (count($parts) >= 2) {
                $coords[] = [(float) $parts[0], (float) $parts[1]];
            }
        }

        return $coords === [] ? null : $coords;
    }

    private function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }
}
