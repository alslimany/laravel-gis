<?php

namespace App\Services;

use App\Exceptions\ReadOnlyLayerException;
use App\Exceptions\SchemaValidationException;
use App\Helpers\GeometryColumnHelper;
use App\Helpers\SpatialHelper;
use App\Models\Layer;
use App\Models\LayerField;
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
        $spatial = DB::connection()->getDriverName() === 'pgsql';

        $query = DB::table($table)->select(array_merge(['id'], $columns));
        if ($spatial) {
            $query->selectRaw("replace(ST_GeometryType({$quotedGeom}), 'ST_', '') as shape");

            if ($geometryType) {
                $query->whereRaw("ST_GeometryType({$quotedGeom}) = ?", [$this->normalizeGeometryType($geometryType)]);
            }
        }

        if ($search) {
            $operator = $spatial ? 'ILIKE' : 'LIKE';
            $query->where(function ($q) use ($search, $columns, $operator) {
                foreach ($columns as $column) {
                    $q->orWhere($column, $operator, "%{$search}%");
                }
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        return [
            'columns' => $spatial ? array_merge(['id', 'shape'], $columns) : array_merge(['id'], $columns),
            'features' => $paginator,
            'shapes' => $spatial ? $this->shapeCounts($table, $geom) : [],
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
        $this->assertWritable($layer);
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

    /**
     * @throws ReadOnlyLayerException
     */
    public function assertWritable(Layer $layer): void
    {
        if ($layer->isSqlView()) {
            throw new ReadOnlyLayerException;
        }
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
    public function create(Layer $layer, array $attributes, ?string $wkt = null, ?array $geojson = null, array $skipRequired = []): array
    {
        $this->assertWritable($layer);
        app(FeatureTableProvisioner::class)->ensureFor($layer);

        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $columns = $this->attributeColumns($table, $geom);

        $attributes = $this->validateAttributes($layer, $attributes, $skipRequired);
        $attributes = $this->applyCalculatedFields($layer, $attributes);

        $data = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $attributes)) {
                $data[$column] = $attributes[$column];
            }
        }

        if ($wkt) {
            $data[$geom] = $this->geometryWrite($wkt, null);
        } elseif ($geojson) {
            $data[$geom] = $this->geometryWrite(null, $geojson);
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
        $this->assertWritable($layer);
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $columns = $this->attributeColumns($table, $geom);

        if ($attributes !== []) {
            // Only incoming keys are schema-validated; legacy stored values may
            // not satisfy the current schema and must not block unrelated edits.
            $attributes = $this->validateAttributes($layer, $attributes, [], true);

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
            $data[$geom] = $this->geometryWrite($wkt, null);
        } elseif ($geojson) {
            $data[$geom] = $this->geometryWrite(null, $geojson);
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
        $this->assertWritable($layer);
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
        $this->assertWritable($layer);
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

        // Carry the style field so unique/graduated renderers can colour tiles.
        $styleColumn = '';
        $styleField = data_get($layer->style_config, 'field');
        if (is_string($styleField) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $styleField)
            && $styleField !== 'id' && $styleField !== $geom && Schema::hasColumn($table, $styleField)) {
            $styleColumn = ', t."'.$styleField.'"';
        }

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
                    t.id{$styleColumn}
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
        if (isset($row[$geom]) && $row[$geom] !== null) {
            if (DB::getDriverName() === 'pgsql') {
                $geo = DB::selectOne('SELECT ST_AsGeoJSON(?) as geojson', [$row[$geom]]);
                $geometry = json_decode($geo->geojson ?? 'null', true);
            } else {
                $geometry = SpatialHelper::wktToGeoJson((string) $row[$geom]);
            }
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
     * Build the geometry column value for a WKT or GeoJSON payload.
     *
     * PostGIS stores EWKB through SQL casts; other drivers store WKT text.
     */
    protected function geometryWrite(?string $wkt, ?array $geojson): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $wkt !== null ? $wkt : SpatialHelper::geoJsonToWkt($geojson ?? []);
        }

        if ($wkt !== null) {
            return DB::raw('ST_SetSRID(ST_GeomFromText('.DB::getPdo()->quote($wkt).'), 4326)');
        }

        $encoded = json_encode($geojson);

        return DB::raw('ST_SetSRID(ST_GeomFromGeoJSON('.DB::getPdo()->quote($encoded).'), 4326)');
    }

    /**
     * Apply calculated layer field expressions.
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
            if ($field->calculated_expression) {
                $attributes[$field->name] = $evaluator->evaluate(
                    $field->calculated_expression,
                    $attributes
                );
            }
        }

        return $attributes;
    }

    /**
     * Validate and coerce attributes against the layer field schema.
     *
     * Throws a SchemaValidationException with a field => message map when any
     * value violates required, type, or domain rules. Calculated fields are
     * skipped: they are computed afterwards and their inputs ignored.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $skipRequired  Field names exempt from the required check (form hidden fields).
     * @param  bool  $partial  Only validate keys present in $attributes (updates never re-validate stored legacy values).
     * @return array<string, mixed> Coerced attributes.
     *
     * @throws SchemaValidationException
     */
    protected function validateAttributes(Layer $layer, array $attributes, array $skipRequired = [], bool $partial = false): array
    {
        $fields = $layer->fields()->orderBy('sort_order')->get();
        if ($fields->isEmpty()) {
            return $attributes;
        }

        $errors = [];

        foreach ($fields as $field) {
            $name = $field->name;
            $label = $field->alias ?: $name;
            $calculated = (bool) $field->calculated_expression;
            $present = array_key_exists($name, $attributes);

            if ($calculated || ($partial && ! $present)) {
                continue;
            }

            $value = $present ? $attributes[$name] : null;

            if ($field->required && ! in_array($name, $skipRequired, true) && ($value === null || $value === '')) {
                $errors[$name] = "The {$label} field is required.";

                continue;
            }

            if ($value === null) {
                continue;
            }

            $type = $this->normalizeFieldType($field->type);

            if ($value === '') {
                // Empty means "no value" for typed fields; strings keep ''.
                if ($type !== 'string') {
                    $attributes[$name] = null;
                }

                continue;
            }

            [$coerced, $error] = $this->coerceFieldValue($label, $type, $value);
            if ($error !== null) {
                $errors[$name] = $error;

                continue;
            }

            $attributes[$name] = $coerced;

            $domainError = $this->validateDomain($field, $label, $type, $coerced);
            if ($domainError !== null) {
                $errors[$name] = $domainError;
            }
        }

        if ($errors !== []) {
            throw new SchemaValidationException(reset($errors), $errors);
        }

        return $attributes;
    }

    /**
     * Map a stored/written field type onto one of the coercion groups.
     */
    protected function normalizeFieldType(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'string', 'text', 'textarea', 'select', 'varchar' => 'string',
            'integer', 'int' => 'integer',
            'float', 'number', 'double', 'decimal', 'real', 'numeric' => 'float',
            'boolean', 'bool', 'checkbox' => 'boolean',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            default => 'mixed',
        };
    }

    /**
     * Coerce a value to its field type, returning [value, errorMessage].
     *
     * @return array{0: mixed, 1: ?string}
     */
    protected function coerceFieldValue(string $label, string $type, mixed $value): array
    {
        if (is_array($value) || is_object($value)) {
            return [null, "The {$label} field must be a single value."];
        }

        switch ($type) {
            case 'string':
                return [is_scalar($value) ? (string) $value : $value, null];

            case 'integer':
                if (is_bool($value)) {
                    return [$value ? 1 : 0, null];
                }
                if (is_int($value)) {
                    return [$value, null];
                }
                if (is_float($value)) {
                    return is_finite($value) && floor($value) === $value
                        ? [(int) $value, null]
                        : [null, "The {$label} field must be a whole number."];
                }
                $trimmed = trim((string) $value);
                if (preg_match('/^[+-]?\d+$/', $trimmed)) {
                    return [(int) $trimmed, null];
                }

                return [null, "The {$label} field must be a whole number."];

            case 'float':
                if (is_bool($value)) {
                    return [$value ? 1.0 : 0.0, null];
                }
                if (is_int($value)) {
                    return [$value, null];
                }
                if (is_float($value)) {
                    return is_finite($value) ? [$value, null] : [null, "The {$label} field must be a number."];
                }
                $trimmed = trim((string) $value);
                if (preg_match('/^[+-]?\d+$/', $trimmed)) {
                    return [(int) $trimmed, null];
                }
                if (is_numeric($trimmed)) {
                    return [(float) $trimmed, null];
                }

                return [null, "The {$label} field must be a number."];

            case 'boolean':
                if (is_bool($value)) {
                    return [$value ? '1' : '0', null];
                }
                if (is_int($value) && ($value === 0 || $value === 1)) {
                    return [$value === 1 ? '1' : '0', null];
                }
                $token = $this->booleanToken($value);
                if ($token !== null) {
                    return [$token, null];
                }

                return [null, "The {$label} field must be a yes/no value."];

            case 'date':
                $timestamp = strtotime(trim((string) $value));
                if ($timestamp === false) {
                    return [null, "The {$label} field must be a valid date."];
                }

                return [date('Y-m-d', $timestamp), null];

            case 'datetime':
                $timestamp = strtotime(trim((string) $value));
                if ($timestamp === false) {
                    return [null, "The {$label} field must be a valid date and time."];
                }

                return [date('Y-m-d H:i:s', $timestamp), null];

            default:
                return [$value, null];
        }
    }

    /**
     * Normalize a value to the canonical '1'/'0' boolean token, or null when invalid.
     */
    protected function booleanToken(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if ($value == 1) {
                return '1';
            }
            if ($value == 0) {
                return '0';
            }

            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return '1';
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return '0';
        }

        return null;
    }

    /**
     * Enforce domain_values when the field defines an allowed set.
     */
    protected function validateDomain(LayerField $field, string $label, string $type, mixed $value): ?string
    {
        $domain = $field->domain_values;
        if (empty($domain) || ! is_array($domain)) {
            return null;
        }

        $entries = [];
        foreach ($domain as $entry) {
            if (is_array($entry)) {
                $entry = $entry['value'] ?? null;
            }
            if ($entry === null || is_array($entry) || is_object($entry)) {
                continue;
            }
            $entries[] = $entry;
        }

        if ($entries === []) {
            return null;
        }

        $allowed = $type === 'boolean'
            ? array_map(fn ($entry) => $this->booleanToken($entry), $entries)
            : array_map(fn ($entry) => is_scalar($entry) ? (string) $entry : '', $entries);
        $allowed = array_values(array_unique(array_filter($allowed)));

        $current = $type === 'boolean'
            ? $this->booleanToken($value)
            : (string) $value;

        if ($allowed === [] || ! in_array($current, $allowed, true)) {
            $display = implode(', ', array_map(fn ($entry) => is_bool($entry) ? ($entry ? 'yes' : 'no') : (string) $entry, $entries));

            return "The {$label} field must be one of: {$display}.";
        }

        return null;
    }
}
