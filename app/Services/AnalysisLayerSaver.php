<?php

namespace App\Services;

use App\Helpers\GeometryColumnHelper;
use App\Models\AnalysisResult;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AnalysisLayerSaver
{
    /**
     * Materialize a saved analysis result as its own layer and table.
     *
     * Copies the recorded feature ids out of the source table into a fresh
     * table, registers a Layer row, and back-links the analysis result so it
     * can be surfaced again from the dashboard catalog.
     *
     * Returns null when none of the recorded feature ids still exist in the
     * source table, so the caller can reject before any DDL runs.
     */
    public function save(AnalysisResult $result, Layer $source, User $owner, ?string $name = null): ?Layer
    {
        $ids = $this->featureIds($result);
        $sourceTable = (string) $source->table_name;

        if ($ids === [] || $sourceTable === '') {
            return null;
        }

        $matched = DB::table($sourceTable)->whereIn('id', $ids)->count();
        if ($matched === 0) {
            return null;
        }

        $driver = DB::getDriverName();
        $table = 'analysis_result_'.uniqid();
        $columns = $this->columnDefinitions($sourceTable, $driver);
        $geometryColumn = GeometryColumnHelper::resolve($sourceTable);
        $layerName = $this->uniqueName($result->organization_id, $name ?: $result->name.' (result)');
        $layer = null;
        $copied = 0;

        DB::transaction(function () use (
            &$layer,
            &$copied,
            $result,
            $source,
            $owner,
            $table,
            $columns,
            $geometryColumn,
            $layerName,
            $ids,
            $driver
        ) {
            DB::statement($this->createTableSql($table, $columns, $driver));

            $copied = $this->copyRows($table, (string) $source->table_name, $columns, $result, $ids);
            $this->syncSequence($table, $driver);
            $this->createSpatialIndex($table, $columns, $geometryColumn, $driver);

            $layer = Layer::create([
                'project_id' => $source->project_id,
                'user_id' => $owner->id,
                'organization_id' => $result->organization_id,
                'name' => $layerName,
                'description' => 'Saved from analysis "'.$result->name.'" on layer "'.$source->name.'".',
                'table_name' => $table,
                'geometry_type' => $source->geometry_type,
                'feature_count' => $copied,
                'style_config' => $source->style_config,
                'published' => false,
                'metadata' => [
                    'analysis_result_id' => $result->id,
                    'source_layer_id' => $source->id,
                ],
            ]);

            $this->copyFields($source, $layer);

            $result->update([
                'summary' => array_merge($result->summary ?? [], [
                    'resulting_layer_id' => $layer->id,
                ]),
            ]);
        });

        return $layer;
    }

    /**
     * Distinct positive feature ids recorded on the result.
     *
     * @return array<int, int>
     */
    protected function featureIds(AnalysisResult $result): array
    {
        return collect($result->feature_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Column definitions of the source table, minus the id column (recreated
     * as a fresh sequence) and any stale analysis back-reference.
     *
     * @return array<int, array{name: string, type: string, notnull: bool, default: string|null}>
     */
    protected function columnDefinitions(string $table, string $driver): array
    {
        if ($driver === 'pgsql') {
            $rows = DB::select(
                'SELECT a.attname AS name,
                        format_type(a.atttypid, a.atttypmod) AS type,
                        a.attnotnull AS notnull,
                        pg_get_expr(ad.adbin, ad.adrelid) AS "default"
                 FROM pg_attribute a
                 LEFT JOIN pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
                 WHERE a.attrelid = to_regclass(?)
                   AND a.attnum > 0
                   AND NOT a.attisdropped
                   AND a.attgenerated = \'\'
                 ORDER BY a.attnum',
                [$table]
            );

            return collect($rows)
                ->reject(fn ($row) => in_array($row->name, ['id', 'analysis_result_id'], true))
                ->map(fn ($row) => [
                    'name' => (string) $row->name,
                    'type' => (string) $row->type,
                    'notnull' => (bool) $row->notnull,
                    'default' => $row->default !== null ? (string) $row->default : null,
                ])
                ->values()
                ->all();
        }

        $escaped = str_replace('"', '""', $table);
        $rows = DB::select('PRAGMA table_info("'.$escaped.'")');

        return collect($rows)
            ->reject(fn ($row) => in_array($row->name, ['id', 'analysis_result_id'], true))
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'type' => $row->type !== null && $row->type !== '' ? (string) $row->type : 'TEXT',
                'notnull' => (bool) $row->notnull,
                'default' => $row->dflt_value !== null ? (string) $row->dflt_value : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{name: string, type: string, notnull: bool, default: string|null}>  $columns
     */
    protected function createTableSql(string $table, array $columns, string $driver): string
    {
        $definitions = [];

        if ($driver === 'pgsql') {
            $definitions[] = '"id" BIGSERIAL PRIMARY KEY';
        } else {
            $definitions[] = '"id" INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        foreach ($columns as $column) {
            $definition = $this->quote($column['name']).' '.$column['type'];

            if ($column['notnull']) {
                $definition .= ' NOT NULL';
            }
            if ($column['default'] !== null && $column['default'] !== '') {
                $definition .= ' DEFAULT '.$column['default'];
            }

            $definitions[] = $definition;
        }

        $definitions[] = $driver === 'pgsql'
            ? '"analysis_result_id" BIGINT NULL'
            : '"analysis_result_id" INTEGER NULL';

        return 'CREATE TABLE '.$this->quote($table).' ('.implode(', ', $definitions).')';
    }

    /**
     * Copy the recorded features, stamping each row with the analysis result.
     *
     * @param  array<int, array{name: string, type: string, notnull: bool, default: string|null}>  $columns
     * @param  array<int, int>  $ids
     */
    protected function copyRows(string $table, string $sourceTable, array $columns, AnalysisResult $result, array $ids): int
    {
        $names = array_map(fn (array $column) => $this->quote($column['name']), $columns);
        $insertColumns = implode(', ', array_merge(['"id"'], $names, ['"analysis_result_id"']));
        $selectColumns = implode(', ', array_merge(['"id"'], $names));
        $copied = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $sql = 'INSERT INTO '.$this->quote($table)
                .' ('.$insertColumns.')'
                .' SELECT '.$selectColumns.', ?'
                .' FROM '.$this->quote($sourceTable)
                .' WHERE "id" IN ('.$placeholders.')';

            $copied += DB::affectingStatement($sql, array_merge([$result->id], $chunk));
        }

        return $copied;
    }

    /**
     * Point the fresh primary key sequence past the copied ids so later
     * feature inserts do not collide.
     */
    protected function syncSequence(string $table, string $driver): void
    {
        if ($driver === 'pgsql') {
            $quoted = $this->quote($table);

            DB::select(
                'SELECT setval(pg_get_serial_sequence(\''.$table.'\', \'id\'),'
                .' COALESCE((SELECT MAX("id") FROM '.$quoted.'), 1))'
            );

            return;
        }

        try {
            $max = (int) DB::table($table)->max('id');
            $sequence = DB::table('sqlite_sequence')->where('name', $table)->first();

            if ($sequence) {
                DB::table('sqlite_sequence')->where('name', $table)->update(['seq' => $max]);
            } else {
                DB::table('sqlite_sequence')->insert(['name' => $table, 'seq' => $max]);
            }
        } catch (\Throwable) {
            // sqlite_sequence bookkeeping is best effort; the row ids are already correct.
        }
    }

    /**
     * @param  array<int, array{name: string, type: string, notnull: bool, default: string|null}>  $columns
     */
    protected function createSpatialIndex(string $table, array $columns, string $geometryColumn, string $driver): void
    {
        if ($driver !== 'pgsql') {
            return;
        }

        $column = collect($columns)->first(fn (array $candidate) => $candidate['name'] === $geometryColumn);
        if (! $column || ! str_starts_with(strtolower($column['type']), 'geometry')) {
            return;
        }

        DB::statement(
            'CREATE INDEX '.$table.'_geom_gix ON '.$this->quote($table)
            .' USING GIST ('.$this->quote($geometryColumn).')'
        );
    }

    /**
     * Carry the source layer's field registry (aliases, domains, calculated
     * fields) onto the result layer.
     */
    protected function copyFields(Layer $source, Layer $layer): void
    {
        foreach ($source->fields()->get() as $field) {
            LayerField::create([
                'layer_id' => $layer->id,
                'name' => $field->name,
                'alias' => $field->alias,
                'type' => $field->type,
                'domain_values' => $field->domain_values,
                'required' => $field->required,
                'calculated_expression' => $field->calculated_expression,
                'sort_order' => $field->sort_order,
            ]);
        }
    }

    /**
     * "<analysis> (result)", then "<analysis> (result) (2)" on collision.
     */
    protected function uniqueName(int $organizationId, string $base): string
    {
        $candidate = $base;
        $suffix = 2;

        while (Layer::query()
            ->where('organization_id', $organizationId)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = $base.' ('.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }

    protected function quote(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }
}
