<?php

namespace App\Services;

use App\Models\DashboardBoard;
use App\Models\Form;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\LayerRelationship;
use App\Models\Map;
use App\Models\Organization;
use App\Models\User;
use App\SolutionPacks\SolutionPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SolutionPackInstaller
{
    /**
     * Install a solution pack into an organization.
     *
     * Safe to re-run: existing pack layers are kept, missing layers are
     * created, relationships and sample features fill in only what is absent.
     * Workspace artifacts (map, form, dashboard) are created by name and
     * skipped when an artifact with the same name already exists in the org.
     *
     * @return array{
     *     layers: list<array{name: string, created: bool}>,
     *     relationships: list<string>,
     *     features: int,
     *     maps: int,
     *     forms: int,
     *     dashboards: int,
     *     installed: bool,
     * }
     */
    public function install(SolutionPack $pack, Organization $organization, User $owner): array
    {
        $definition = $pack->definition();
        $summary = ['layers' => [], 'relationships' => [], 'features' => 0, 'maps' => 0, 'forms' => 0, 'dashboards' => 0, 'installed' => false];
        $layers = $this->packLayers($pack, $organization);

        foreach ($definition['layers'] as $layerDefinition) {
            if (isset($layers[$layerDefinition['name']])) {
                $summary['layers'][] = ['name' => $layerDefinition['name'], 'created' => false];

                continue;
            }

            $layer = $this->createLayer($pack, $organization, $owner, $layerDefinition);
            $layers[$layer->name] = $layer;
            $summary['layers'][] = ['name' => $layer->name, 'created' => true];
            $summary['installed'] = true;
        }

        foreach ($definition['relationships'] as $relationship) {
            $parent = $layers[$relationship['parent']];
            $child = $layers[$relationship['child']];

            if ($this->relationshipExists($parent, $child, $relationship['foreign_key'])) {
                continue;
            }

            $this->ensureKeyColumn($child, $relationship['foreign_key']);

            LayerRelationship::create([
                'layer_id' => $parent->id,
                'related_layer_id' => $child->id,
                'name' => $relationship['name'],
                'foreign_key' => $relationship['foreign_key'],
            ]);

            $summary['relationships'][] = $relationship['name'];
            $summary['installed'] = true;
        }

        $summary['features'] = $this->seedFeatures($definition['features'], $layers);
        if ($summary['features'] > 0) {
            $summary['installed'] = true;
        }

        $summary['maps'] = $this->createMaps($definition['maps'] ?? [], $organization, $owner, $layers);
        if ($summary['maps'] > 0) {
            $summary['installed'] = true;
        }

        $summary['forms'] = $this->createForms($definition['forms'] ?? [], $organization, $owner, $layers);
        if ($summary['forms'] > 0) {
            $summary['installed'] = true;
        }

        $summary['dashboards'] = $this->createDashboards($definition['dashboards'] ?? [], $organization, $owner, $layers);
        if ($summary['dashboards'] > 0) {
            $summary['installed'] = true;
        }

        return $summary;
    }

    /**
     * Whether every layer and workspace artifact of the pack exists in the organization.
     */
    public function isInstalled(SolutionPack $pack, Organization $organization): bool
    {
        $existing = array_keys($this->packLayers($pack, $organization));
        if ($existing === []) {
            return false;
        }

        $definition = $pack->definition();

        foreach ($definition['layers'] as $layerDefinition) {
            if (! in_array($layerDefinition['name'], $existing, true)) {
                return false;
            }
        }

        foreach (['maps' => Map::class, 'forms' => Form::class, 'dashboards' => DashboardBoard::class] as $key => $model) {
            foreach ($definition[$key] ?? [] as $artifact) {
                $exists = $model::where('organization_id', $organization->id)
                    ->where('name', $artifact['name'])
                    ->exists();

                if (! $exists) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Pack layers already present in the organization, keyed by layer name.
     *
     * @return array<string, Layer>
     */
    public function packLayers(SolutionPack $pack, Organization $organization): array
    {
        $layers = [];

        foreach (Layer::where('organization_id', $organization->id)->get() as $layer) {
            if (($layer->metadata['solution_pack'] ?? null) === $pack->slug()) {
                $layers[$layer->name] = $layer;
            }
        }

        return $layers;
    }

    /**
     * Create the layer table, layer row, and typed field registry.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function createLayer(SolutionPack $pack, Organization $organization, User $owner, array $definition): Layer
    {
        $driver = DB::getDriverName();
        $table = 'pack_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($definition['name'])).'_'.uniqid();

        $columns = [
            $driver === 'pgsql'
                ? '"id" BIGSERIAL PRIMARY KEY'
                : '"id" INTEGER PRIMARY KEY AUTOINCREMENT',
        ];

        foreach ($definition['fields'] as $field) {
            $columns[] = $this->quoteIdent($field['name']).' '.$this->columnType((string) ($field['type'] ?? 'string'), $driver).' NULL';
        }

        $columns[] = $driver === 'pgsql'
            ? '"geom" geometry(Geometry, 4326)'
            : '"geom" TEXT NULL';

        DB::statement('CREATE TABLE '.$this->quoteIdent($table).' ('.implode(', ', $columns).')');

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX '.$table.'_geom_gix ON '.$this->quoteIdent($table).' USING GIST (geom)');
        }

        $layer = Layer::create([
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'table_name' => $table,
            'geometry_type' => $definition['geometry_type'],
            'feature_count' => 0,
            'published' => false,
            'style_config' => $definition['style'] ?? null,
            'metadata' => ['solution_pack' => $pack->slug()],
        ]);

        foreach ($definition['fields'] as $index => $field) {
            LayerField::create([
                'layer_id' => $layer->id,
                'name' => $field['name'],
                'alias' => $field['alias'] ?? $field['name'],
                'type' => $field['type'] ?? 'string',
                'domain_values' => $field['domain'] ?? null,
                'required' => (bool) ($field['required'] ?? false),
                'calculated_expression' => $field['calculated'] ?? null,
                'sort_order' => $index,
            ]);
        }

        return $layer;
    }

    /**
     * Whether the parent/child/foreign-key triple already exists.
     */
    protected function relationshipExists(Layer $parent, Layer $child, string $foreignKey): bool
    {
        return LayerRelationship::where('layer_id', $parent->id)
            ->where('related_layer_id', $child->id)
            ->where('foreign_key', $foreignKey)
            ->exists();
    }

    /**
     * Add the foreign-key column to the child table when missing.
     */
    protected function ensureKeyColumn(Layer $child, string $foreignKey): void
    {
        if (Schema::hasColumn($child->table_name, $foreignKey)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $columnType = $driver === 'pgsql' ? 'BIGINT' : 'INTEGER';

        DB::statement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteIdent($child->table_name),
            $this->quoteIdent($foreignKey),
            $columnType
        ));
    }

    /**
     * Seed sample features for layers whose tables are still empty.
     *
     * @param  list<array{layer: string, attributes: array<string, mixed>, wkt?: ?string, refs?: array<string, array{layer: string, name: string}>}>  $features
     * @param  array<string, Layer>  $layers
     */
    protected function seedFeatures(array $features, array $layers): int
    {
        $grouped = [];
        foreach ($features as $sample) {
            $grouped[$sample['layer']][] = $sample;
        }

        $seeded = 0;
        $featureService = app(FeatureService::class);

        foreach ($grouped as $layerName => $samples) {
            if (! isset($layers[$layerName])) {
                continue;
            }

            $layer = $layers[$layerName];
            if (DB::table($layer->table_name)->count() > 0) {
                continue;
            }

            foreach ($samples as $sample) {
                $attributes = $sample['attributes'];

                foreach ($sample['refs'] ?? [] as $column => $ref) {
                    if (! isset($layers[$ref['layer']])) {
                        throw new RuntimeException("Sample feature references unknown layer {$ref['layer']}.");
                    }

                    $parentId = DB::table($layers[$ref['layer']]->table_name)
                        ->where('name', $ref['name'])
                        ->value('id');

                    if ($parentId === null) {
                        throw new RuntimeException("Sample feature references missing parent row {$ref['name']}.");
                    }

                    $attributes[$column] = $parentId;
                }

                $featureService->create($layer, $attributes, $sample['wkt'] ?? null);
                $seeded++;
            }
        }

        return $seeded;
    }

    /**
     * Create starter maps for the pack, skipping names already used in the org.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, Layer>  $layers
     */
    protected function createMaps(array $definitions, Organization $organization, User $owner, array $layers): int
    {
        $created = 0;

        foreach ($definitions as $definition) {
            $exists = Map::where('organization_id', $organization->id)
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $entries = [];
            foreach ($definition['layers'] as $layerName) {
                if (! isset($layers[$layerName])) {
                    throw new RuntimeException("Pack map references unknown layer {$layerName}.");
                }

                $layer = $layers[$layerName];
                $entries[] = [
                    'id' => $layer->id,
                    'name' => $layer->name,
                    'type' => 'mvt',
                    'visible' => true,
                    'mvtUrl' => '/api/layers/'.$layer->id.'/tiles/{z}/{x}/{y}.mvt',
                    'style_config' => $layer->style_config,
                    'geometry_type' => $layer->geometry_type,
                ];
            }

            Map::create([
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'user_id' => $owner->id,
                'organization_id' => $organization->id,
                'viewport' => $definition['viewport'] ?? null,
                'basemap' => $definition['basemap'] ?? 'osm',
                'layers' => $entries,
                'is_public' => false,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Create pack forms, skipping names already used in the org.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, Layer>  $layers
     */
    protected function createForms(array $definitions, Organization $organization, User $owner, array $layers): int
    {
        $created = 0;
        $forms = app(FormSubmissionService::class);

        foreach ($definitions as $definition) {
            $exists = Form::where('organization_id', $organization->id)
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            if (! isset($layers[$definition['layer']])) {
                throw new RuntimeException("Pack form references unknown layer {$definition['layer']}.");
            }

            Form::create([
                'organization_id' => $organization->id,
                'layer_id' => $layers[$definition['layer']]->id,
                'user_id' => $owner->id,
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'schema' => $forms->normalizeSchema($definition['schema'] ?? []),
                'is_public' => (bool) ($definition['is_public'] ?? false),
                'collect_geometry' => (bool) ($definition['collect_geometry'] ?? false),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Create pack dashboards, skipping names already used in the org.
     *
     * Widget definitions may reference layers by name; they are resolved to
     * layer ids and normalized before persisting.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, Layer>  $layers
     */
    protected function createDashboards(array $definitions, Organization $organization, User $owner, array $layers): int
    {
        $created = 0;
        $document = app(DashboardWidgetDocument::class);

        foreach ($definitions as $definition) {
            $exists = DashboardBoard::where('organization_id', $organization->id)
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $widgets = [];
            foreach ($definition['widgets'] ?? [] as $widget) {
                $layerName = $widget['layer'] ?? null;
                unset($widget['layer']);

                if ($layerName !== null) {
                    if (! isset($layers[$layerName])) {
                        throw new RuntimeException("Pack dashboard widget references unknown layer {$layerName}.");
                    }

                    $widget['layer_id'] = $layers[$layerName]->id;
                }

                $widgets[] = $widget;
            }

            DashboardBoard::create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'widgets' => $document->normalize($widgets),
                'is_public' => false,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Map a pack field type to a table column type.
     */
    protected function columnType(string $type, string $driver): string
    {
        return match ($type) {
            'integer' => $driver === 'pgsql' ? 'BIGINT' : 'INTEGER',
            'float', 'number' => $driver === 'pgsql' ? 'DOUBLE PRECISION' : 'NUMERIC',
            'boolean' => 'BOOLEAN',
            'date' => $driver === 'pgsql' ? 'DATE' : 'TEXT',
            'datetime', 'timestamp' => $driver === 'pgsql' ? 'TIMESTAMP' : 'TEXT',
            default => 'TEXT',
        };
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }
}
