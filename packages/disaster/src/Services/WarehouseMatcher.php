<?php

namespace Lumina\Disaster\Services;

use App\Helpers\SpatialHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lumina\Disaster\Models\InventoryItem;
use Lumina\Disaster\Models\Warehouse;
use Lumina\Disaster\Support\Enums\WarehouseStatus;
use Lumina\Disaster\Support\GeometryReader;

/**
 * Suggest nearest warehouse(s) with available stock for a resource type.
 * PostGIS ST_Distance on geography when available; haversine fallback on SQLite
 * (same pattern as ExposureComputer / SpatialHelper).
 */
final class WarehouseMatcher
{
    public function usesPostgis(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @return list<array{
     *     warehouse: Warehouse,
     *     inventory_item: InventoryItem,
     *     quantity_available: int,
     *     distance_m: float|null
     * }>
     */
    public function nearestWithStock(
        int $organizationId,
        int $resourceTypeId,
        int $quantityNeeded,
        ?float $latitude = null,
        ?float $longitude = null,
        int $limit = 5,
    ): array {
        $candidates = InventoryItem::query()
            ->with(['warehouse', 'resourceType'])
            ->where('organization_id', $organizationId)
            ->where('resource_type_id', $resourceTypeId)
            ->where('quantity', '>=', $quantityNeeded)
            ->whereHas('warehouse', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                    ->where('status', WarehouseStatus::Active->value);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $scored = $this->scoreByDistance($candidates, $latitude, $longitude);

        return $scored
            ->sortBy(fn (array $row) => $row['distance_m'] ?? PHP_FLOAT_MAX)
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     warehouse: Warehouse,
     *     inventory_item: InventoryItem,
     *     quantity_available: int,
     *     distance_m: float|null
     * }|null
     */
    public function bestMatch(
        int $organizationId,
        int $resourceTypeId,
        int $quantityNeeded,
        ?float $latitude = null,
        ?float $longitude = null,
    ): ?array {
        $matches = $this->nearestWithStock(
            $organizationId,
            $resourceTypeId,
            $quantityNeeded,
            $latitude,
            $longitude,
            1,
        );

        return $matches[0] ?? null;
    }

    /**
     * @param  Collection<int, InventoryItem>  $candidates
     * @return Collection<int, array{
     *     warehouse: Warehouse,
     *     inventory_item: InventoryItem,
     *     quantity_available: int,
     *     distance_m: float|null
     * }>
     */
    protected function scoreByDistance(Collection $candidates, ?float $latitude, ?float $longitude): Collection
    {
        if ($latitude === null || $longitude === null) {
            return $candidates->map(fn (InventoryItem $item) => [
                'warehouse' => $item->warehouse,
                'inventory_item' => $item,
                'quantity_available' => (int) $item->quantity,
                'distance_m' => null,
            ]);
        }

        if ($this->usesPostgis()) {
            return $this->scorePostgis($candidates, $latitude, $longitude);
        }

        return $candidates->map(function (InventoryItem $item) use ($latitude, $longitude) {
            $coords = GeometryReader::pointCoordinates($item->warehouse?->geom);
            $distance = $coords === null
                ? null
                : SpatialHelper::distance(
                    $latitude,
                    $longitude,
                    $coords['latitude'],
                    $coords['longitude'],
                );

            return [
                'warehouse' => $item->warehouse,
                'inventory_item' => $item,
                'quantity_available' => (int) $item->quantity,
                'distance_m' => $distance !== null ? round($distance, 2) : null,
            ];
        });
    }

    /**
     * @param  Collection<int, InventoryItem>  $candidates
     * @return Collection<int, array{
     *     warehouse: Warehouse,
     *     inventory_item: InventoryItem,
     *     quantity_available: int,
     *     distance_m: float|null
     * }>
     */
    protected function scorePostgis(Collection $candidates, float $latitude, float $longitude): Collection
    {
        $warehouseIds = $candidates->pluck('warehouse_id')->unique()->values()->all();
        if ($warehouseIds === []) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
        $bindings = array_merge([$longitude, $latitude], $warehouseIds);

        $rows = DB::select(
            "SELECT id, ST_Distance(
                geom::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            ) AS distance_m
            FROM dm_warehouses
            WHERE id IN ({$placeholders})
              AND geom IS NOT NULL",
            $bindings,
        );

        $distances = collect($rows)->mapWithKeys(
            fn ($row) => [(int) $row->id => (float) $row->distance_m],
        );

        return $candidates->map(function (InventoryItem $item) use ($distances) {
            $distance = $distances->get((int) $item->warehouse_id);

            return [
                'warehouse' => $item->warehouse,
                'inventory_item' => $item,
                'quantity_available' => (int) $item->quantity,
                'distance_m' => $distance !== null ? round($distance, 2) : null,
            ];
        });
    }
}
