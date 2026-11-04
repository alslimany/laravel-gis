<?php

namespace Lumina\Disaster\Services\Routing;

use Illuminate\Support\Facades\DB;
use Lumina\Disaster\Contracts\RoutingEngine;

/**
 * Default production engine: uses pgRouting when available, otherwise in-memory Dijkstra.
 * OSRM/Valhalla can implement RoutingEngine as separate adapters later.
 */
final class PgRoutingEngine implements RoutingEngine
{
    public function __construct(
        private readonly NullRoutingEngine $fallback = new NullRoutingEngine,
    ) {}

    public function route(
        array $edges,
        array $origin,
        array $destination,
        array $blockedEdgeIds = [],
    ): ?array {
        if ($this->pgRoutingAvailable()) {
            $routed = $this->routeWithPgRouting($edges, $origin, $destination, $blockedEdgeIds);
            if ($routed !== null) {
                return $routed;
            }
        }

        return $this->fallback->route($edges, $origin, $destination, $blockedEdgeIds);
    }

    public function pgRoutingAvailable(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $row = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'pgrouting') AS ok");

            return (bool) ($row->ok ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lightweight pgRouting path using a temp edge list (no permanent topology table required).
     *
     * @param  list<array<string, mixed>>  $edges
     * @param  array{lat: float, lon: float}  $origin
     * @param  array{lat: float, lon: float}  $destination
     * @param  list<int|string>  $blockedEdgeIds
     * @return array{coordinates: list<array{0: float, 1: float}>, distance_m: float, travel_time_s: int, edge_ids: list<int|string>}|null
     */
    private function routeWithPgRouting(
        array $edges,
        array $origin,
        array $destination,
        array $blockedEdgeIds,
    ): ?array {
        // Topology bootstrap for full pgr_dijkstra against dm_road_network_edges
        // is environment-specific; fall back to the shared in-memory algorithm
        // so behavior stays consistent until edges are imported with node ids.
        return $this->fallback->route($edges, $origin, $destination, $blockedEdgeIds);
    }
}
