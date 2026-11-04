<?php

namespace Lumina\Disaster\Contracts;

/**
 * Pathfinding adapter for evacuation routing (pgRouting default; OSRM/Valhalla later).
 */
interface RoutingEngine
{
    /**
     * @param  list<array{
     *     id: int|string,
     *     from_node: string,
     *     to_node: string,
     *     length_m: float,
     *     free_flow_s: int,
     *     is_blocked: bool,
     *     coordinates?: list<array{0: float, 1: float}>
     * }>  $edges
     * @param  array{lat: float, lon: float}  $origin
     * @param  array{lat: float, lon: float}  $destination
     * @param  list<int|string>  $blockedEdgeIds
     * @return array{
     *     coordinates: list<array{0: float, 1: float}>,
     *     distance_m: float,
     *     travel_time_s: int,
     *     edge_ids: list<int|string>
     * }|null
     */
    public function route(
        array $edges,
        array $origin,
        array $destination,
        array $blockedEdgeIds = [],
    ): ?array;
}
