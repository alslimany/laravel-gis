<?php

namespace Lumina\Disaster\Services\Routing;

use Lumina\Disaster\Contracts\RoutingEngine;

/**
 * In-memory Dijkstra for SQLite tests and environments without pgRouting.
 * Falls back to a straight zone→shelter polyline when no usable graph exists.
 */
final class NullRoutingEngine implements RoutingEngine
{
    public function route(
        array $edges,
        array $origin,
        array $destination,
        array $blockedEdgeIds = [],
    ): ?array {
        $blocked = array_fill_keys(array_map(static fn ($id) => (string) $id, $blockedEdgeIds), true);

        $usable = [];
        foreach ($edges as $edge) {
            $id = (string) $edge['id'];
            if (($edge['is_blocked'] ?? false) || isset($blocked[$id])) {
                continue;
            }
            $usable[] = $edge;
        }

        if ($usable === []) {
            return $this->straightLine($origin, $destination, blockedByNetwork: $edges !== []);
        }

        $graph = $this->buildGraph($usable);
        $originNode = $this->nearestNode($graph['nodes'], $origin);
        $destNode = $this->nearestNode($graph['nodes'], $destination);

        if ($originNode === null || $destNode === null) {
            return $this->straightLine($origin, $destination, blockedByNetwork: false);
        }

        $path = $this->dijkstra($graph['adj'], $originNode, $destNode);
        if ($path === null) {
            return null;
        }

        return $this->pathToResult($path, $graph['edgeByKey'], $origin, $destination);
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return array{
     *     nodes: array<string, array{lat: float, lon: float}>,
     *     adj: array<string, list<array{to: string, cost: float, time: int, edge_id: string, key: string}>>,
     *     edgeByKey: array<string, array<string, mixed>>
     * }
     */
    private function buildGraph(array $edges): array
    {
        $nodes = [];
        $adj = [];
        $edgeByKey = [];

        foreach ($edges as $edge) {
            $id = (string) $edge['id'];
            $from = (string) $edge['from_node'];
            $to = (string) $edge['to_node'];
            $coords = $edge['coordinates'] ?? null;
            $length = (float) $edge['length_m'];
            $time = (int) $edge['free_flow_s'];

            if (is_array($coords) && count($coords) >= 2) {
                $first = $coords[0];
                $last = $coords[count($coords) - 1];
                $nodes[$from] = ['lon' => (float) $first[0], 'lat' => (float) $first[1]];
                $nodes[$to] = ['lon' => (float) $last[0], 'lat' => (float) $last[1]];
            } else {
                $nodes[$from] ??= ['lat' => 0.0, 'lon' => 0.0];
                $nodes[$to] ??= ['lat' => 0.0, 'lon' => 0.0];
            }

            $fwdKey = $id.':fwd';
            $revKey = $id.':rev';
            $edgeByKey[$fwdKey] = $edge;
            $edgeByKey[$revKey] = $edge;

            $adj[$from][] = ['to' => $to, 'cost' => $length, 'time' => $time, 'edge_id' => $id, 'key' => $fwdKey];
            $adj[$to][] = ['to' => $from, 'cost' => $length, 'time' => $time, 'edge_id' => $id, 'key' => $revKey];
        }

        return ['nodes' => $nodes, 'adj' => $adj, 'edgeByKey' => $edgeByKey];
    }

    /**
     * @param  array<string, array{lat: float, lon: float}>  $nodes
     * @param  array{lat: float, lon: float}  $point
     */
    private function nearestNode(array $nodes, array $point): ?string
    {
        $best = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($nodes as $id => $node) {
            $d = $this->haversineM($point['lat'], $point['lon'], $node['lat'], $node['lon']);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, list<array{to: string, cost: float, time: int, edge_id: string, key: string}>>  $adj
     * @return array{nodes: list<string>, edge_ids: list<string>, keys: list<string>, distance_m: float, travel_time_s: int}|null
     */
    private function dijkstra(array $adj, string $start, string $goal): ?array
    {
        $dist = [$start => 0.0];
        $time = [$start => 0];
        $prev = [];
        $prevEdge = [];
        $prevKey = [];
        $queue = [$start => 0.0];

        while ($queue !== []) {
            asort($queue);
            $u = array_key_first($queue);
            unset($queue[$u]);

            if ($u === $goal) {
                break;
            }

            foreach ($adj[$u] ?? [] as $link) {
                $alt = $dist[$u] + $link['cost'];
                $v = $link['to'];
                if (! isset($dist[$v]) || $alt < $dist[$v]) {
                    $dist[$v] = $alt;
                    $time[$v] = ($time[$u] ?? 0) + $link['time'];
                    $prev[$v] = $u;
                    $prevEdge[$v] = $link['edge_id'];
                    $prevKey[$v] = $link['key'];
                    $queue[$v] = $alt;
                }
            }
        }

        if (! isset($dist[$goal])) {
            return null;
        }

        $nodes = [];
        $edgeIds = [];
        $keys = [];
        $cur = $goal;
        while ($cur !== $start) {
            array_unshift($nodes, $cur);
            array_unshift($edgeIds, $prevEdge[$cur]);
            array_unshift($keys, $prevKey[$cur]);
            $cur = $prev[$cur];
        }
        array_unshift($nodes, $start);

        return [
            'nodes' => $nodes,
            'edge_ids' => $edgeIds,
            'keys' => $keys,
            'distance_m' => $dist[$goal],
            'travel_time_s' => $time[$goal],
        ];
    }

    /**
     * @param  array{nodes: list<string>, edge_ids: list<string>, keys: list<string>, distance_m: float, travel_time_s: int}  $path
     * @param  array<string, array<string, mixed>>  $edgeByKey
     * @param  array{lat: float, lon: float}  $origin
     * @param  array{lat: float, lon: float}  $destination
     * @return array{coordinates: list<array{0: float, 1: float}>, distance_m: float, travel_time_s: int, edge_ids: list<string>}
     */
    private function pathToResult(array $path, array $edgeByKey, array $origin, array $destination): array
    {
        $coordinates = [[$origin['lon'], $origin['lat']]];

        foreach ($path['keys'] as $key) {
            $edge = $edgeByKey[$key] ?? null;
            $coords = is_array($edge) ? ($edge['coordinates'] ?? null) : null;
            if (! is_array($coords) || $coords === []) {
                continue;
            }
            $reversed = str_ends_with($key, ':rev');
            $seq = $reversed ? array_reverse($coords) : $coords;
            foreach ($seq as $coord) {
                $coordinates[] = [(float) $coord[0], (float) $coord[1]];
            }
        }

        $coordinates[] = [$destination['lon'], $destination['lat']];

        return [
            'coordinates' => $coordinates,
            'distance_m' => round($path['distance_m'], 2),
            'travel_time_s' => (int) $path['travel_time_s'],
            'edge_ids' => $path['edge_ids'],
        ];
    }

    /**
     * @param  array{lat: float, lon: float}  $origin
     * @param  array{lat: float, lon: float}  $destination
     * @return array{coordinates: list<array{0: float, 1: float}>, distance_m: float, travel_time_s: int, edge_ids: list<int|string>}|null
     */
    private function straightLine(array $origin, array $destination, bool $blockedByNetwork): ?array
    {
        if ($blockedByNetwork) {
            // Graph existed but every edge was blocked — no safe route.
            return null;
        }

        $distance = $this->haversineM($origin['lat'], $origin['lon'], $destination['lat'], $destination['lon']);

        return [
            'coordinates' => [
                [$origin['lon'], $origin['lat']],
                [$destination['lon'], $destination['lat']],
            ],
            'distance_m' => round($distance, 2),
            'travel_time_s' => max(1, (int) round($distance / 11.1)),
            'edge_ids' => [],
        ];
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
