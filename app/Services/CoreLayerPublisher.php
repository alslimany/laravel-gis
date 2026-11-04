<?php

namespace App\Services;

use App\Contracts\LayerPublisher;
use App\Helpers\GeometryColumnHelper;
use App\Models\Layer;
use App\Models\Organization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class CoreLayerPublisher implements LayerPublisher
{
    /** Used when the SQL returns no rows, so GeoServer still gets a valid extent. */
    public const FALLBACK_BBOX = [-180.0, -90.0, 180.0, 90.0];

    private FeatureTableProvisioner $tables;

    public function __construct(
        private GeoServerService $geoserver,
        ?FeatureTableProvisioner $tables = null,
    ) {
        $this->tables = $tables ?? new FeatureTableProvisioner;
    }

    /**
     * Publish (or refresh) a read-only layer backed by a SQL view.
     *
     * SECURITY: $sql is run as DDL (CREATE VIEW ... AS $sql) and sent to
     * GeoServer verbatim. It must come from code only (a plugin publisher),
     * never from user input. Values may only be interpolated after strict
     * casting (e.g. (int) organization id). A single statement is required:
     * SQL that still contains ';' after trimming is rejected.
     *
     * The layer row is always stored with metadata.sql_view = true, which
     * makes FeatureService refuse feature writes on it.
     *
     * @return array{layer: Layer, published: bool, backing_view: bool}
     */
    public function upsertSqlViewLayer(
        Organization $organization,
        array $attributes,
        ?string $sql = null,
        ?array $sqlView = null,
        bool $syncGeoServer = true,
    ): array {
        $tableName = (string) ($attributes['table_name'] ?? '');
        if ($tableName === '') {
            throw new RuntimeException('LayerPublisher requires attributes.table_name.');
        }

        if ($sql !== null) {
            $sql = self::assertSingleStatement($sql);
        }

        $existing = Layer::query()
            ->where('organization_id', $organization->getKey())
            ->where('table_name', $tableName)
            ->first();

        $ownerId = $attributes['user_id']
            ?? $existing?->user_id
            ?? $organization->user_id
            ?? $organization->members()->orderBy('id')->value('id');

        if ($ownerId === null) {
            throw new RuntimeException(
                "Cannot publish layer for organization {$organization->getKey()}: no user_id available."
            );
        }

        $layer = Layer::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'table_name' => $tableName,
            ],
            [
                'user_id' => $ownerId,
                'name' => (string) ($attributes['name'] ?? $tableName),
                'description' => $attributes['description'] ?? null,
                'geometry_type' => $attributes['geometry_type'] ?? null,
                'feature_count' => $attributes['feature_count'] ?? 0,
                'style_config' => $attributes['style_config'] ?? null,
                'metadata' => array_merge((array) ($attributes['metadata'] ?? []), ['sql_view' => true]),
            ],
        );

        // The map builder, vector tiles, extents and attribute tables all read
        // `layers.table_name` from Postgres. A SQL-view layer that exists only in
        // GeoServer leaves that name empty (or lets the provisioner create an empty
        // placeholder table), so the layer draws nothing. Back it with a real view.
        $backingView = false;
        if ($sql !== null) {
            $backingView = $this->syncBackingView($layer, $sql);
        }

        $published = false;
        if ($syncGeoServer && $sql !== null && $sqlView !== null) {
            $published = $this->publishSqlView($organization, $sql, $sqlView);
            if ($published) {
                $layer->markAsPublished(
                    (string) $sqlView['layerName'],
                    $organization->getGeoServerWorkspace(),
                );
            }
        }

        return ['layer' => $layer->fresh(), 'published' => $published, 'backing_view' => $backingView];
    }

    /**
     * @param  array{
     *     layerName: string,
     *     geometryName?: string,
     *     geometryType?: string,
     *     srid?: int,
     *     keyColumn?: string,
     *     title?: string,
     *     abstract?: string,
     *     styleName?: string,
     *     styleSld?: string
     * }  $sqlView
     */
    protected function publishSqlView(Organization $organization, string $sql, array $sqlView): bool
    {
        $workspace = $organization->getGeoServerWorkspace();
        $datastore = (string) Config::get('geoserver.datastore', 'postgis');
        $layerName = (string) $sqlView['layerName'];

        try {
            if (! $this->geoserver->workspaceExists($workspace)) {
                $this->geoserver->createWorkspace($workspace);
            }
            if (! $this->geoserver->datastoreExists($workspace, $datastore)) {
                $this->geoserver->createPostGISDatastore($workspace, $datastore);
            }

            $geometryName = (string) ($sqlView['geometryName'] ?? 'geom');
            $geometryType = (string) ($sqlView['geometryType'] ?? 'MultiPolygon');

            $ok = $this->geoserver->publishSqlView($workspace, $datastore, $layerName, $sql, [
                'geometryName' => $geometryName,
                'geometryType' => $geometryType,
                'bbox' => $this->extentForSql($sql, $geometryName),
                'srid' => $sqlView['srid'] ?? 4326,
                'keyColumn' => $sqlView['keyColumn'] ?? 'id',
                'title' => $sqlView['title'] ?? $layerName,
                'abstract' => $sqlView['abstract'] ?? null,
            ]);

            if ($ok && ! empty($sqlView['styleName']) && ! empty($sqlView['styleSld'])) {
                $styleName = (string) $sqlView['styleName'];
                $this->geoserver->createOrUpdateStyle($workspace, $styleName, (string) $sqlView['styleSld']);
                $this->geoserver->applyStyleToLayer($workspace, $layerName, $styleName);
            } elseif ($ok) {
                // Never leave a layer on whatever GeoServer guessed (or nothing).
                $this->geoserver->applyStyleToLayer(
                    $workspace,
                    $layerName,
                    self::defaultStyleFor($geometryType),
                );
            }

            return $ok;
        } catch (Throwable $e) {
            Log::warning('core.layer_publisher.geoserver_failed', [
                'organization_id' => $organization->getKey(),
                'layer' => $layerName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Trim trailing whitespace/semicolons and refuse anything that still has a ';'
     * (multiple statements or a smuggled one).
     */
    public static function assertSingleStatement(string $sql): string
    {
        $inner = rtrim(trim($sql), "; \t\n\r");
        if ($inner === '' || str_contains($inner, ';')) {
            throw new \InvalidArgumentException('LayerPublisher SQL must be a single statement without \';\'.');
        }

        return $inner;
    }

    /** Built-in GeoServer style that draws the given geometry type. */
    public static function defaultStyleFor(?string $geometryType): string
    {
        $type = strtolower((string) $geometryType);

        return match (true) {
            str_contains($type, 'point') => 'point',
            str_contains($type, 'line') => 'line',
            str_contains($type, 'polygon') => 'polygon',
            default => 'generic',
        };
    }

    /**
     * WGS84 extent of the SQL's rows, or the world when it has none.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function extentForSql(string $sql, string $geometryName = 'geom'): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return self::FALLBACK_BBOX;
        }

        $geom = '"'.str_replace('"', '', $geometryName).'"';
        $inner = rtrim(trim($sql), "; \t\n\r");

        try {
            $row = DB::selectOne(
                "SELECT ST_XMin(e) AS minx, ST_YMin(e) AS miny, ST_XMax(e) AS maxx, ST_YMax(e) AS maxy
                 FROM (SELECT ST_Extent(ST_Transform({$geom}, 4326)) AS e FROM ({$inner}) AS q WHERE {$geom} IS NOT NULL) AS x"
            );
        } catch (Throwable $e) {
            Log::warning('core.layer_publisher.extent_failed', ['error' => $e->getMessage()]);

            return self::FALLBACK_BBOX;
        }

        $bounds = LayerExtent::coerceBounds($row ? [$row->minx, $row->miny, $row->maxx, $row->maxy] : null);
        if ($bounds === null) {
            return self::FALLBACK_BBOX;
        }

        // A single point has a zero-size box; pad it so GeoServer and viewers can frame it.
        if ($bounds[2] - $bounds[0] < 1e-6 || $bounds[3] - $bounds[1] < 1e-6) {
            $pad = 0.001;
            $bounds = [
                max(-180.0, $bounds[0] - $pad),
                max(-90.0, $bounds[1] - $pad),
                min(180.0, $bounds[2] + $pad),
                min(90.0, $bounds[3] + $pad),
            ];
        }

        return $bounds;
    }

    /**
     * A table may only be swapped for the view when it is the empty placeholder
     * the feature-table provisioner creates (exactly id + geom, no rows) and the
     * layer is flagged metadata.sql_view = true. Anything else is refused.
     */
    public function assertReplaceablePlaceholder(Layer $layer, string $table): void
    {
        if (! $layer->isSqlView()) {
            throw new RuntimeException("Layer [{$table}] is not flagged sql_view; refusing to replace its table with a SQL view.");
        }

        $columns = array_map('strtolower', Schema::getColumnListing($table));
        sort($columns);
        if ($columns !== ['geom', 'id']) {
            throw new RuntimeException("Table [{$table}] is not an id+geom placeholder; refusing to replace it with a SQL view.");
        }

        if (DB::table($table)->exists()) {
            throw new RuntimeException("Table [{$table}] holds data; refusing to replace it with a SQL view.");
        }
    }

    /**
     * Make `layers.table_name` a Postgres view over the layer's SQL.
     * Only an empty id+geom placeholder table on a sql_view layer is dropped;
     * any other table is left untouched and the view is not created.
     */
    protected function syncBackingView(Layer $layer, string $sql): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        $table = $this->tables->assertSafeTableName((string) $layer->table_name);
        $quoted = '"'.$table.'"';
        $inner = self::assertSingleStatement($sql);

        try {
            DB::transaction(function () use ($layer, $table, $quoted, $inner) {
                if (Schema::hasTable($table)) {
                    $this->assertReplaceablePlaceholder($layer, $table);
                    DB::statement("DROP TABLE {$quoted}");
                }

                // DROP + CREATE (not CREATE OR REPLACE) so column changes in the SQL are allowed.
                DB::statement("DROP VIEW IF EXISTS {$quoted}");
                DB::statement("CREATE VIEW {$quoted} AS {$inner}");
            });
        } catch (Throwable $e) {
            Log::warning('core.layer_publisher.backing_view_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            GeometryColumnHelper::forget($table);
        }

        return true;
    }
}
