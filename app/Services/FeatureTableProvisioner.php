<?php

namespace App\Services;

use App\Helpers\GeometryColumnHelper;
use App\Models\Layer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates empty PostGIS (or SQLite) feature tables for drawable layers.
 *
 * Layer catalog rows alone are not enough — FeatureService inserts into
 * `$layer->table_name`, so that relation must exist before draw/save.
 *
 * ## DG-0.2 provisioning fallback
 *
 * When `public.lumina_promote_layer_table(text,text)` exists (roles from
 * `docker/postgres/roles.sql` applied on a throwaway/staging DB, then the
 * DG-0.2 migration), `createEmpty` builds the table in schema `staging` and
 * promotes it into `public` owned by `lumina_layer_owner`.
 *
 * When those roles/functions are absent (Abdullah's local demo DB, default CI
 * sqlite/pgsql without roles.sql), this class keeps the pre-DG-0.2 behaviour:
 * `CREATE TABLE` directly in the connection's default schema. That path is
 * intentional until gate DG-0.7 / DG-0.3 cutover — do not apply roles.sql to
 * the demo volume from here.
 *
 * Other call sites that still create tables directly (to be wired later):
 * {@see AnalysisLayerSaver}, {@see ExcelImportService}, {@see CoreLayerPublisher}
 * (SQL views). Imports soft-opt into staging+promote via {@see promoteLayerTable}
 * when available ({@see DataImportService}, {@see ExcelImportService}).
 */
class FeatureTableProvisioner
{
    private ?bool $promoteAvailable = null;

    /**
     * Ensure the layer's feature table exists. Creates an empty id+geom table
     * when missing. No-ops when the table is already present (import / pack
     * layers that point at existing data).
     */
    public function ensureFor(Layer $layer): bool
    {
        $table = trim((string) $layer->table_name);
        if ($table === '') {
            throw new InvalidArgumentException('Layer has no table_name.');
        }

        // SQL-view layers are backed by a Postgres view; never shadow one with an empty table.
        if (Schema::hasTable($table) || Schema::hasView($table)) {
            return false;
        }

        $geometryType = $this->normalizeGeometryType($layer->geometry_type);
        $this->createEmpty($table, $geometryType);
        GeometryColumnHelper::forget($table);

        return true;
    }

    /**
     * Create an empty feature table with a primary key and geometry column.
     *
     * On PostgreSQL with DG-0.2 helpers available: create in `staging`, then
     * {@see promoteLayerTable}. Otherwise: direct CREATE (fallback).
     *
     * @param  string  $geometryType  PostGIS type name (e.g. LineString) or Geometry
     */
    public function createEmpty(string $table, string $geometryType = 'Geometry'): void
    {
        $table = $this->assertSafeTableName($table);

        if (Schema::hasTable($table)) {
            throw new RuntimeException("Table [{$table}] already exists.");
        }

        $driver = Schema::getConnection()->getDriverName();
        $geometryType = $this->normalizeGeometryType($geometryType);

        if ($driver === 'pgsql' && $this->isPromoteAvailable()) {
            $this->createEmptyViaPromote($table, $geometryType);
            GeometryColumnHelper::forget($table);

            return;
        }

        $this->createEmptyDirect($table, $geometryType, $driver);
        GeometryColumnHelper::forget($table);
    }

    /**
     * True when the SECURITY DEFINER promote function is present.
     *
     * Used by imports to opt into staging → public promotion without requiring
     * a separate feature flag. False on sqlite and on DBs where roles.sql /
     * the DG-0.2 migration have not been applied.
     */
    public function isPromoteAvailable(): bool
    {
        if ($this->promoteAvailable !== null) {
            return $this->promoteAvailable;
        }

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return $this->promoteAvailable = false;
        }

        try {
            $row = DB::selectOne(
                "SELECT to_regprocedure('public.lumina_promote_layer_table(text,text)') AS oid"
            );
            $this->promoteAvailable = $row !== null && $row->oid !== null;
        } catch (\Throwable) {
            $this->promoteAvailable = false;
        }

        return $this->promoteAvailable;
    }

    /**
     * Move staging.<stagingName> to public.<targetName> via SECURITY DEFINER.
     *
     * @throws RuntimeException when the promote function is not installed
     */
    public function promoteLayerTable(string $stagingName, string $targetName): void
    {
        $stagingName = $this->assertSafeTableName($stagingName);
        $targetName = $this->assertSafeTableName($targetName);

        if (! $this->isPromoteAvailable()) {
            throw new RuntimeException(
                'lumina_promote_layer_table is not available. Apply docker/postgres/roles.sql on a non-demo DB, then re-run migrations (DG-0.2).'
            );
        }

        DB::statement('SELECT public.lumina_promote_layer_table(?, ?)', [$stagingName, $targetName]);
        GeometryColumnHelper::forget($targetName);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertSafeTableName(string $table): string
    {
        $table = strtolower(trim($table));

        if ($table === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException(
                'Table name must start with a letter and contain only lowercase letters, numbers, and underscores.'
            );
        }

        $reserved = ['layers', 'maps', 'users', 'organizations', 'migrations', 'sessions', 'jobs', 'cache'];
        if (in_array($table, $reserved, true)) {
            throw new InvalidArgumentException("Table name [{$table}] is reserved.");
        }

        return $table;
    }

    protected function createEmptyViaPromote(string $table, string $geometryType): void
    {
        $quoted = $this->quoteIdent($table);
        $columns = [
            '"id" BIGSERIAL PRIMARY KEY',
            '"geom" geometry('.$geometryType.', 4326)',
        ];

        DB::statement('CREATE TABLE staging.'.$quoted.' ('.implode(', ', $columns).')');

        $index = $this->assertSafeTableName($table.'_geom_gix');
        DB::statement(
            'CREATE INDEX '.$this->quoteIdent($index).' ON staging.'.$quoted.' USING GIST (geom)'
        );

        $this->promoteLayerTable($table, $table);
    }

    protected function createEmptyDirect(string $table, string $geometryType, string $driver): void
    {
        $quoted = $this->quoteIdent($table);

        $columns = [
            $driver === 'pgsql'
                ? '"id" BIGSERIAL PRIMARY KEY'
                : '"id" INTEGER PRIMARY KEY AUTOINCREMENT',
            $driver === 'pgsql'
                ? '"geom" geometry('.$geometryType.', 4326)'
                : '"geom" TEXT NULL',
        ];

        DB::statement('CREATE TABLE '.$quoted.' ('.implode(', ', $columns).')');

        if ($driver === 'pgsql') {
            $index = $this->assertSafeTableName($table.'_geom_gix');
            DB::statement('CREATE INDEX '.$this->quoteIdent($index).' ON '.$quoted.' USING GIST (geom)');
        }
    }

    protected function normalizeGeometryType(?string $geometryType): string
    {
        $type = trim((string) $geometryType);
        if ($type === '' || strcasecmp($type, 'auto') === 0) {
            return 'Geometry';
        }

        $allowed = [
            'Point', 'MultiPoint',
            'LineString', 'MultiLineString',
            'Polygon', 'MultiPolygon',
            'GeometryCollection', 'Geometry',
        ];

        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, $type) === 0) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException("Unsupported geometry type [{$geometryType}].");
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }
}
