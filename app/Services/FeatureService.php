<?php

namespace App\Services;

use App\Helpers\GeometryColumnHelper;
use App\Helpers\SpatialHelper;
use App\Models\Layer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FeatureService
{
    /**
     * List features as attribute rows (geometry as WKT).
     */
    public function list(Layer $layer, int $perPage = 25, ?string $search = null, ?string $geometryType = null): array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $columns = $this->attributeColumns($table, $geom);
        $quotedGeom = $this->quoteIdent($geom);

        $query = DB::table($table)->select(array_merge(['id'], $columns))
            ->selectRaw("replace(ST_GeometryType({$quotedGeom}), 'ST_', '') as shape");

        if ($geometryType) {
            $query->whereRaw("ST_GeometryType({$quotedGeom}) = ?", [$this->normalizeGeometryType($geometryType)]);
        }

        if ($search) {
            $query->where(function ($q) use ($search, $columns) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'ILIKE', "%{$search}%");
                }
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        return [
            'columns' => array_merge(['id', 'shape'], $columns),
            'features' => $paginator,
            'shapes' => $this->shapeCounts($table, $geom),
        ];
    }

    /**
     * @return array<int, array{type: string, count: int}>
     */
    public function shapeCounts(string $table, ?string $geom = null): array
    {
        $geom ??= GeometryColumnHelper::resolve($table);
        $quotedTable = $this->quoteIdent($table);
        $quotedGeom = $this->quoteIdent($geom);
        $rows = DB::select("SELECT replace(ST_GeometryType({$quotedGeom}), 'ST_', '') AS type, count(*)::int AS count FROM {$quotedTable} GROUP BY 1 ORDER BY 2 DESC");

        return array_map(fn ($row) => ['type' => $row->type, 'count' => (int) $row->count], $rows);
    }

    public function deleteByShape(Layer $layer, string $geometryType): int
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $type = $this->normalizeGeometryType($geometryType);
        $deleted = DB::table($table)
            ->whereRaw('ST_GeometryType('.$this->quoteIdent($geom).') = ?', [$type])
            ->delete();

        if ($deleted > 0) {
            $layer->update(['feature_count' => DB::table($table)->count()]);
        }

        return $deleted;
    }

    protected function normalizeGeometryType(string $geometryType): string
    {
        $type = str_starts_with($geometryType, 'ST_') ? $geometryType : 'ST_'.$geometryType;
        $allowed = ['ST_Point', 'ST_MultiPoint', 'ST_LineString', 'ST_MultiLineString', 'ST_Polygon', 'ST_MultiPolygon', 'ST_GeometryCollection'];
        if (! in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown geometry type.');
        }

        return $type;
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }

    /**
     * Get a single feature as GeoJSON Feature.
     */
    public function find(Layer $layer, int|string $featureId): ?array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);

        $row = DB::table($table)->where('id', $featureId)->first();
        if (! $row) {
            return null;
        }

        return $this->rowToFeature((array) $row, $geom);
    }

    /**
     * Create a feature from WKT or GeoJSON geometry plus attributes.
     */
    public function create(Layer $layer, array $attributes, ?string $wkt = null, ?array $geojson = null): array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $columns = $this->attributeColumns($table, $geom);

        $attributes = $this->applyCalculatedFields($layer, $attributes);

        $data = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $attributes)) {
                $data[$column] = $attributes[$column];
            }
        }

        if ($wkt) {
            $data[$geom] = DB::raw("ST_SetSRID(ST_GeomFromText(".DB::getPdo()->quote($wkt)."), 4326)");
        } elseif ($geojson) {
            $encoded = json_encode($geojson);
            $data[$geom] = DB::raw("ST_SetSRID(ST_GeomFromGeoJSON(".DB::getPdo()->quote($encoded)."), 4326)");
        }

        $id = DB::table($table)->insertGetId($data);
        $layer->update(['feature_count' => DB::table($table)->count()]);

        return $this->find($layer, $id) ?? ['id' => $id];
    }

    /**
     * Update feature attributes and/or geometry.
     */
    public function update(Layer $layer, int|string $featureId, array $attributes = [], ?string $wkt = null, ?array $geojson = null): ?array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $columns = $this->attributeColumns($table, $geom);

        if ($attributes !== []) {
            $existing = DB::table($table)->where('id', $featureId)->first();
            $merged = array_merge($existing ? (array) $existing : [], $attributes);
            unset($merged[$geom], $merged['id']);
            $attributes = $this->applyCalculatedFields($layer, $merged);
        }

        $data = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $attributes)) {
                $data[$column] = $attributes[$column];
            }
        }

        if ($wkt) {
            $data[$geom] = DB::raw("ST_SetSRID(ST_GeomFromText(".DB::getPdo()->quote($wkt)."), 4326)");
        } elseif ($geojson) {
            $encoded = json_encode($geojson);
            $data[$geom] = DB::raw("ST_SetSRID(ST_GeomFromGeoJSON(".DB::getPdo()->quote($encoded)."), 4326)");
        }

        if ($data === []) {
            return $this->find($layer, $featureId);
        }

        DB::table($table)->where('id', $featureId)->update($data);

        return $this->find($layer, $featureId);
    }

    /**
     * Delete a feature.
     */
    public function delete(Layer $layer, int|string $featureId): bool
    {
        $table = $layer->table_name;
        $deleted = DB::table($table)->where('id', $featureId)->delete() > 0;
        if ($deleted) {
            $layer->update(['feature_count' => DB::table($table)->count()]);
        }

        return $deleted;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    public function deleteMany(Layer $layer, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $table = $layer->table_name;
        $existing = DB::table($table)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($existing === []) {
            return [];
        }

        DB::table($table)->whereIn('id', $existing)->delete();
        $layer->update(['feature_count' => DB::table($table)->count()]);

        return $existing;
    }

    /**
     * FeatureCollection GeoJSON for a layer.
     */
    public function toGeoJson(Layer $layer, ?string $timeFilter = null): array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $timeField = $layer->metadata['time_field'] ?? null;

        $sql = "
            SELECT jsonb_build_object(
                'type', 'FeatureCollection',
                'features', COALESCE(jsonb_agg(feature), '[]'::jsonb)
            ) as geojson
            FROM (
                SELECT jsonb_build_object(
                    'type', 'Feature',
                    'id', row.id,
                    'geometry', ST_AsGeoJSON(row.{$geom})::jsonb,
                    'properties', to_jsonb(row) - '{$geom}'
                ) as feature
                FROM {$table} row
        ";

        $bindings = [];
        if ($timeFilter && $timeField && Schema::hasColumn($table, $timeField)) {
            $sql .= " WHERE row.{$timeField}::text = ?";
            $bindings[] = $timeFilter;
        }

        $sql .= ') features';

        $result = DB::selectOne($sql, $bindings);

        return json_decode($result->geojson ?? '{"type":"FeatureCollection","features":[]}', true);
    }

    /**
     * Generate an MVT tile for a layer.
     */
    public function tile(
        Layer $layer,
        int $z,
        int $x,
        int $y,
        ?string $timeField = null,
        ?string $timeFrom = null,
        ?string $timeTo = null
    ): string {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $layerName = preg_replace('/[^a-zA-Z0-9_]/', '_', $layer->table_name);
        $timeField = $timeField ?: ($layer->metadata['time_field'] ?? null);

        $timeClause = '';
        $bindings = [$z, $x, $y];
        if ($timeField && Schema::hasColumn($table, $timeField)) {
            if ($timeFrom) {
                $timeClause .= " AND t.{$timeField} >= ?";
                $bindings[] = $timeFrom;
            }
            if ($timeTo) {
                $timeClause .= " AND t.{$timeField} <= ?";
                $bindings[] = $timeTo;
            }
        }
        $bindings[] = $layerName;

        $sql = "
            WITH bounds AS (
                SELECT ST_TileEnvelope(?, ?, ?) AS geom
            ),
            mvtgeom AS (
                SELECT
                    ST_AsMVTGeom(
                        ST_Transform(t.{$geom}, 3857),
                        bounds.geom,
                        4096,
                        64,
                        true
                    ) AS geom,
                    t.id
                FROM {$table} t, bounds
                WHERE ST_Intersects(
                    ST_Transform(t.{$geom}, 3857),
                    bounds.geom
                )
                {$timeClause}
            )
            SELECT ST_AsMVT(mvtgeom, ?, 4096, 'geom') AS tile
            FROM mvtgeom
        ";

        $result = DB::selectOne($sql, $bindings);
        $tile = $result->tile ?? '';

        // Postgres bytea comes back as a stream from PDO. An unconsumed
        // resource makes the tile response a 500, so vector tiles never draw.
        if (is_resource($tile)) {
            $tile = stream_get_contents($tile) ?: '';
        }

        return is_string($tile) ? $tile : '';
    }

    /**
     * Attribute columns excluding geometry and system columns that should not be mass-assigned blindly.
     *
     * @return list<string>
     */
    public function attributeColumns(string $table, ?string $geom = null): array
    {
        $geom ??= GeometryColumnHelper::resolve($table);

        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            fn ($column) => ! in_array($column, [$geom, 'id'], true)
        ));
    }

    /**
     * Distinct non-null values for one attribute column.
     *
     * @return list<mixed>
     */
    public function distinctValues(Layer $layer, string $field, int $limit = 100): array
    {
        $table = $layer->table_name;
        if (! $table || ! in_array($field, $this->attributeColumns($table), true)) {
            return [];
        }

        $column = $this->quoteIdent($field);
        $tableSql = $this->quoteIdent($table);
        $limit = max(1, min($limit, 200));

        $rows = DB::select(
            "SELECT DISTINCT {$column} AS value FROM {$tableSql} WHERE {$column} IS NOT NULL ORDER BY {$column} LIMIT {$limit}"
        );

        return array_map(fn ($row) => $row->value, $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function rowToFeature(array $row, string $geom): array
    {
        $geometry = null;
        if (isset($row[$geom])) {
            $geo = DB::selectOne('SELECT ST_AsGeoJSON(?) as geojson', [$row[$geom]]);
            $geometry = json_decode($geo->geojson ?? 'null', true);
        }

        $properties = $row;
        unset($properties[$geom]);

        return [
            'type' => 'Feature',
            'id' => $row['id'] ?? null,
            'geometry' => $geometry,
            'properties' => $properties,
        ];
    }

    /**
     * Apply calculated layer field expressions and enforce required fields.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function applyCalculatedFields(Layer $layer, array $attributes): array
    {
        $fields = $layer->fields()->orderBy('sort_order')->get();
        if ($fields->isEmpty()) {
            return $attributes;
        }

        $evaluator = app(ExpressionEvaluator::class);

        foreach ($fields as $field) {
            if ($field->required) {
                $value = $attributes[$field->name] ?? null;
                if ($value === null || $value === '') {
                    throw new \InvalidArgumentException("Required field missing: {$field->name}");
                }
            }

            if ($field->calculated_expression) {
                $attributes[$field->name] = $evaluator->evaluate(
                    $field->calculated_expression,
                    $attributes
                );
            }
        }

        return $attributes;
    }
}
