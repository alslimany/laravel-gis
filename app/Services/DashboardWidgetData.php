<?php

namespace App\Services;

use App\Helpers\GeometryColumnHelper;
use App\Models\AnalysisResult;
use App\Models\DashboardBoard;
use App\Models\Layer;
use App\Models\Map;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardWidgetData
{
    /**
     * Aggregate dashboard widgets.
     *
     * Layer, saved-map, and saved-analysis links are optional. A text widget,
     * or any widget left unconfigured, stays on the dashboard without a data source.
     *
     * @param  array<int, array<string, mixed>>  $widgets
     * @param  array<string, string>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function build(int $organizationId, array $widgets, array $filters = [], bool $public = false, ?string $shareToken = null): array
    {
        $results = [];
        $categoryFields = $this->categoryFields($widgets);

        foreach ($widgets as $index => $widget) {
            $type = $widget['type'] ?? 'indicator';
            $entry = $this->entry($widget, $index, $type);

            try {
                $entry = array_merge($entry, $this->resolve(
                    $organizationId,
                    $type,
                    $widget,
                    $filters,
                    $categoryFields,
                    $public,
                    $shareToken,
                ));
            } catch (\Throwable $e) {
                report($e);
                $entry['status'] = 'error';
                $entry['message'] = null;
                $entry['error'] = $public
                    ? 'This widget could not be loaded.'
                    : 'Could not read this widget.';
            }

            $results[] = $entry;
        }

        return $results;
    }

    /**
     * Drop map widgets that were never pointed at a layer or a saved map.
     * Configured maps, including ones that failed to load, stay visible.
     *
     * @param  array<int, array<string, mixed>>  $widgets
     * @param  array<int, array<string, mixed>>  $data
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public function withoutUnconfiguredMaps(array $widgets, array $data): array
    {
        $hidden = [];
        foreach ($data as $entry) {
            if (($entry['type'] ?? '') !== 'map') {
                continue;
            }
            if (($entry['status'] ?? '') === 'empty' && empty($entry['map'])) {
                $hidden[(string) ($entry['id'] ?? '')] = true;
            }
        }

        $keep = fn (array $widget): bool => ! isset($hidden[(string) ($widget['id'] ?? '')]);

        return [
            array_values(array_filter($widgets, $keep)),
            array_values(array_filter($data, $keep)),
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    protected function entry(array $widget, int $index, string $type): array
    {
        return [
            'id' => $widget['id'] ?? ('w_'.$index),
            'index' => $index,
            'type' => $type,
            'title' => $widget['title'] ?? ('Widget '.($index + 1)),
            'layout' => $widget['layout'] ?? null,
            'prefix' => $widget['prefix'] ?? null,
            'suffix' => $widget['suffix'] ?? null,
            'body' => $widget['body'] ?? null,
            'chart_style' => $widget['chart_style'] ?? 'bar',
            'layer_id' => $widget['layer_id'] ?? null,
            'map_id' => isset($widget['map_id']) && (int) $widget['map_id'] ? (int) $widget['map_id'] : null,
            'analysis_id' => isset($widget['analysis_id']) && (int) $widget['analysis_id'] ? (int) $widget['analysis_id'] : null,
            'source' => $type === 'map' ? $this->mapSource($widget) : ($this->dataSource($widget, $type) === 'analysis' ? 'analysis' : null),
            'labels' => [],
            'values' => [],
            'rows' => [],
            'value' => null,
            'options' => [],
            'map' => null,
            'error' => null,
            'status' => 'ok',
            'message' => null,
            'filtered' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $categoryFields
     * @return array<string, mixed>
     */
    protected function resolve(int $organizationId, string $type, array $widget, array $filters, array $categoryFields, bool $public = false, ?string $shareToken = null): array
    {
        if ($type === 'text') {
            return ['status' => 'ok'];
        }

        if ($type === 'map' && $this->mapSource($widget) === 'map') {
            return $this->savedMap($organizationId, $widget, $public);
        }

        if ($this->dataSource($widget, $type) === 'analysis') {
            return $this->analysisResult($organizationId, $type, $widget, $filters, $categoryFields);
        }

        $layerId = $widget['layer_id'] ?? null;
        if (! $layerId) {
            return $this->empty($this->unlinkedMessage($type));
        }

        $layer = Layer::query()
            ->where('id', $layerId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $layer) {
            if ($public && $type === 'map') {
                return $this->hiddenMap();
            }

            return $this->failure('Layer or table not found.');
        }

        if (! $layer->table_name || ! Schema::hasTable($layer->table_name)) {
            return $this->failure('Layer or table not found.');
        }

        $requested = $filters[(string) $layerId] ?? null;
        $filterValue = $type === 'category' ? null : $requested;
        $filterField = $categoryFields[(string) $layerId] ?? null;

        $partial = $this->aggregateLayer($layer, $type, $widget, $filterValue, $filterField, $public, $shareToken);
        if ($type === 'category') {
            $partial['value'] = ($requested === null || $requested === '') ? null : (string) $requested;
        }

        return $partial;
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<string, string>
     */
    protected function categoryFields(array $widgets): array
    {
        $fields = [];
        foreach ($widgets as $widget) {
            if (($widget['type'] ?? '') !== 'category') {
                continue;
            }
            $layerId = $widget['layer_id'] ?? null;
            $field = $widget['group_by'] ?? $widget['column'] ?? null;
            if ($layerId && is_string($field) && $field !== '') {
                $fields[(string) $layerId] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $widget
     */
    public function mapSource(array $widget): string
    {
        $source = strtolower((string) ($widget['source'] ?? ''));
        if ($source === 'map' || $source === 'layer') {
            return $source;
        }

        return ! empty($widget['map_id']) ? 'map' : 'layer';
    }

    /**
     * Indicator, chart, table, and list widgets may read a saved analysis.
     * Anything else stays on the layer path, which itself may be unconfigured.
     *
     * @param  array<string, mixed>  $widget
     */
    public function dataSource(array $widget, string $type): string
    {
        if ($type === 'map') {
            return $this->mapSource($widget);
        }

        $source = strtolower((string) ($widget['source'] ?? ''));
        if ($source === 'analysis' && in_array($type, DashboardWidgetDocument::ANALYSIS_TYPES, true)) {
            return 'analysis';
        }

        return 'layer';
    }

    /**
     * A public dashboard may serve tiles only for a layer a map widget
     * points at directly. Saved-map layers use that map's own share route,
     * and a private map contributes no layers.
     */
    public function shareExposesLayer(DashboardBoard $dashboard, Layer $layer): bool
    {
        if (! $dashboard->is_public || (int) $dashboard->organization_id !== (int) $layer->organization_id) {
            return false;
        }

        $widgets = app(DashboardWidgetDocument::class)->normalize($dashboard->widgets ?? []);
        foreach ($widgets as $widget) {
            if (($widget['type'] ?? '') !== 'map' || $this->mapSource($widget) !== 'layer') {
                continue;
            }
            if ((int) ($widget['layer_id'] ?? 0) === (int) $layer->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    protected function savedMap(int $organizationId, array $widget, bool $public = false): array
    {
        $mapId = isset($widget['map_id']) ? (int) $widget['map_id'] : 0;
        if ($mapId === 0) {
            return $this->empty('Choose a saved map when this widget should show one.');
        }

        $map = Map::query()
            ->where('id', $mapId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $map || ($public && ! $map->is_public)) {
            return $public
                ? $this->hiddenMap()
                : $this->failure('Saved map not found.');
        }

        return [
            'status' => 'ok',
            'error' => null,
            'message' => null,
            'source' => 'map',
            'map_id' => $map->id,
            'map' => $public
                ? app(PublicShareMap::class)->present($map)
                : $this->presentSavedMap($map),
        ];
    }

    /**
     * Public shares drop a map widget instead of describing a private map.
     *
     * @return array<string, mixed>
     */
    protected function hiddenMap(): array
    {
        return [
            'status' => 'empty',
            'message' => null,
            'error' => null,
            'map' => null,
            'map_id' => null,
            'source' => 'map',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSavedMap(Map $map): array
    {
        $layers = [];
        foreach (is_array($map->layers) ? $map->layers : [] as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $layers[] = $this->hydrateMapLayer($layer);
        }

        $viewport = is_array($map->viewport) ? $map->viewport : [];

        return [
            'id' => $map->id,
            'name' => $map->name,
            'basemap' => $map->basemap ?: 'osm',
            'viewport' => [
                'center' => $viewport['center'] ?? [0, 20],
                'zoom' => $viewport['zoom'] ?? 2,
                'rotation' => $viewport['rotation'] ?? 0,
            ],
            'layers' => $layers,
        ];
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    protected function hydrateMapLayer(array $layer): array
    {
        $type = $layer['type'] ?? null;
        if ($type === 'wms' || ! empty($layer['url'])) {
            return $layer;
        }

        $id = $layer['id'] ?? null;
        if ($id && empty($layer['mvtUrl'])) {
            $layer['type'] = $type ?: 'mvt';
            $layer['mvtUrl'] = url("/api/layers/{$id}/tiles/{z}/{x}/{y}.mvt");
        }

        if (! array_key_exists('visible', $layer)) {
            $layer['visible'] = true;
        }

        return $layer;
    }

    /**
     * Aggregate a saved query or analysis over the features it recorded.
     * The link is optional: a widget with no analysis stays empty.
     *
     * @param  array<string, mixed>  $widget
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $categoryFields
     * @return array<string, mixed>
     */
    protected function analysisResult(int $organizationId, string $type, array $widget, array $filters, array $categoryFields): array
    {
        $analysisId = isset($widget['analysis_id']) ? (int) $widget['analysis_id'] : 0;
        if ($analysisId === 0) {
            return $this->empty('Choose a saved analysis when this widget should show a result.');
        }

        $result = AnalysisResult::query()
            ->where('id', $analysisId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $result) {
            return $this->failure('Saved analysis not found.');
        }

        $ids = collect($result->feature_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $layer = $result->layer_id
            ? Layer::query()->where('id', $result->layer_id)->where('organization_id', $organizationId)->first()
            : null;

        $meta = [
            'source' => 'analysis',
            'analysis_id' => $result->id,
            'layer_id' => $layer?->id,
        ];

        if (! $layer || ! $layer->table_name || ! Schema::hasTable($layer->table_name)) {
            if ($type === 'indicator' && strtolower((string) ($widget['aggregation'] ?? 'count')) === 'count') {
                return array_merge($meta, [
                    'status' => 'ok',
                    'value' => (int) $result->feature_count,
                    'error' => null,
                    'message' => null,
                ]);
            }

            return array_merge($meta, $this->failure('Layer or table not found.'));
        }

        if ($ids === []) {
            if ($type === 'indicator' && strtolower((string) ($widget['aggregation'] ?? 'count')) === 'count') {
                return array_merge($meta, [
                    'status' => 'ok',
                    'value' => (int) $result->feature_count,
                    'error' => null,
                    'message' => null,
                ]);
            }

            return array_merge($meta, $this->empty('This analysis has no features.'));
        }

        $requested = $filters[(string) $layer->id] ?? null;
        $filterValue = $requested;
        $filterField = $categoryFields[(string) $layer->id] ?? null;
        $partial = $this->aggregateLayer($layer, $type, $widget, $filterValue, $filterField, false, null, $ids);

        return array_merge($meta, $partial);
    }

    /**
     * @param  array<string, mixed>  $widget
     * @param  array<int, int>|null  $onlyIds
     * @return array<string, mixed>
     */
    protected function aggregateLayer(Layer $layer, string $type, array $widget, ?string $filterValue, ?string $filterField, bool $public = false, ?string $shareToken = null, ?array $onlyIds = null): array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $column = $this->safeColumn($table, $widget['column'] ?? null);
        $groupBy = $this->safeColumn($table, $widget['group_by'] ?? $column);
        $agg = strtolower((string) ($widget['aggregation'] ?? 'count'));
        $query = DB::table($table);
        if ($onlyIds !== null) {
            if (! Schema::hasColumn($table, 'id')) {
                return $this->failure('Layer or table not found.');
            }
            $query->whereIn('id', $onlyIds);
        }
        $filtered = $this->applyCategoryFilter($query, $table, $filterValue, $filterField);

        if ($type === 'indicator') {
            if (in_array($agg, ['sum', 'avg'], true) && ! $column) {
                return $this->empty('Choose a numeric column.', $filtered);
            }

            $value = match ($agg) {
                'sum' => (float) (clone $query)->sum($column),
                'avg' => $this->average($query, $column),
                default => (int) (clone $query)->count(),
            };

            return [
                'status' => 'ok',
                'value' => $value,
                'filtered' => $filtered,
                'error' => null,
            ];
        }

        if ($type === 'category') {
            $field = $groupBy ?: $column;
            if (! $field) {
                return $this->empty('Choose a field when this filter should drive the dashboard.');
            }

            $options = (clone $query)
                ->select($field)
                ->whereNotNull($field)
                ->distinct()
                ->orderBy($field)
                ->limit(100)
                ->pluck($field)
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all();

            return [
                'status' => 'ok',
                'error' => null,
                'labels' => $options,
                'options' => $options,
                'message' => $options === [] ? 'No values in this field yet.' : null,
                'filtered' => false,
            ];
        }

        if (in_array($type, ['serial', 'pie'], true)) {
            if (! $groupBy) {
                return $this->empty('Choose a field to group by.', $filtered);
            }
            if (in_array($agg, ['sum', 'avg'], true) && ! $column) {
                return $this->empty('Choose a numeric column.', $filtered);
            }

            $selectAgg = match ($agg) {
                'sum' => DB::raw('SUM('.$query->getGrammar()->wrap($column).') as value'),
                'avg' => DB::raw('AVG('.$query->getGrammar()->wrap($column).') as value'),
                default => DB::raw('COUNT(*) as value'),
            };

            $rows = (clone $query)
                ->select($groupBy, $selectAgg)
                ->groupBy($groupBy)
                ->orderByDesc('value')
                ->limit(25)
                ->get();

            $labels = $rows->pluck($groupBy)->map(fn ($value) => (string) ($value ?? 'null'))->all();

            return [
                'status' => 'ok',
                'error' => null,
                'labels' => $labels,
                'values' => $rows->pluck('value')->map(fn ($value) => (float) $value)->all(),
                'message' => $labels === []
                    ? ($filtered ? 'No features match this filter.' : 'No features in this layer.')
                    : null,
                'filtered' => $filtered,
            ];
        }

        if ($type === 'map') {
            $style = is_array($layer->style_config) ? $layer->style_config : [];
            $mvtUrl = ($public && $shareToken)
                ? app(PublicShareMap::class)->dashboardTileUrl($shareToken, $layer->id)
                : url("/api/layers/{$layer->id}/tiles/{z}/{x}/{y}.mvt");

            return [
                'status' => 'ok',
                'error' => null,
                'message' => null,
                'source' => 'layer',
                'filtered' => $filtered,
                'map' => [
                    'basemap' => $widget['basemap'] ?? 'osm',
                    'viewport' => ['center' => [0, 20], 'zoom' => 2],
                    'layers' => [[
                        'id' => $layer->id,
                        'name' => $layer->name,
                        'type' => 'mvt',
                        'mvtUrl' => $mvtUrl,
                        'visible' => true,
                        'opacity' => 1,
                        'style_config' => [
                            'fill_color' => $style['fill_color'] ?? '#06b6d4',
                            'stroke_color' => $style['stroke_color'] ?? '#dae2fd',
                            'stroke_width' => $style['stroke_width'] ?? 1,
                        ],
                    ]],
                ],
            ];
        }

        if ($type === 'list') {
            $titleField = $this->safeColumn($table, $widget['title_field'] ?? null);
            $descriptionField = $this->safeColumn($table, $widget['description_field'] ?? null);
            $columns = array_values(array_filter([$titleField, $descriptionField]));
            if (! $columns) {
                $all = array_values(array_filter(
                    Schema::getColumnListing($table),
                    fn ($name) => $name !== $geom && $name !== 'id'
                ));
                $columns = array_slice($all, 0, 2);
                $titleField = $columns[0] ?? null;
                $descriptionField = $columns[1] ?? null;
            }

            $limit = min((int) ($widget['limit'] ?? 20), 100);
            $rowsQuery = (clone $query)->select($columns ?: ['*'])->limit($limit);
            if (Schema::hasColumn($table, 'id')) {
                $rowsQuery->orderBy('id');
            }
            $rows = $rowsQuery->get();
            $mapped = $rows->map(fn ($row) => [
                'title' => $titleField ? (string) (($row->{$titleField}) ?? '') : '',
                'description' => $descriptionField ? (string) (($row->{$descriptionField}) ?? '') : '',
            ])->all();

            return [
                'status' => 'ok',
                'error' => null,
                'labels' => array_values(array_filter([$titleField, $descriptionField])),
                'rows' => $mapped,
                'message' => $mapped === []
                    ? ($filtered ? 'No features match this filter.' : 'No features in this layer.')
                    : null,
                'filtered' => $filtered,
            ];
        }

        if ($type !== 'table') {
            return $this->empty('This widget type is not available.');
        }

        $requestedColumns = is_array($widget['columns'] ?? null) ? $widget['columns'] : null;
        if ($requestedColumns) {
            $columns = array_values(array_filter(
                array_map(fn ($name) => $this->safeColumn($table, $name), $requestedColumns)
            ));
        } else {
            $columns = array_values(array_filter(
                Schema::getColumnListing($table),
                fn ($name) => $name !== $geom
            ));
        }

        if (! $columns) {
            return $this->empty('Choose columns when this table should show attributes.', $filtered);
        }

        $limit = min((int) ($widget['limit'] ?? 20), 100);
        $rowsQuery = (clone $query)->select($columns)->limit($limit);
        if (Schema::hasColumn($table, 'id')) {
            $rowsQuery->orderBy('id');
        }
        $rows = $rowsQuery->get()->map(fn ($row) => (array) $row)->all();

        return [
            'status' => 'ok',
            'error' => null,
            'labels' => $columns,
            'rows' => $rows,
            'message' => $rows === []
                ? ($filtered ? 'No features match this filter.' : 'No features in this layer.')
                : null,
            'filtered' => $filtered,
        ];
    }

    /**
     * @param  Builder  $query
     */
    protected function average($query, string $column): ?float
    {
        $raw = (clone $query)->avg($column);

        return $raw === null ? null : (float) $raw;
    }

    protected function safeColumn(string $table, mixed $column): ?string
    {
        if (! is_string($column) || $column === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return null;
        }

        return Schema::hasColumn($table, $column) ? $column : null;
    }

    /**
     * @param  Builder  $query
     */
    protected function applyCategoryFilter($query, string $table, ?string $filterValue, ?string $filterField): bool
    {
        if ($filterValue === null || $filterValue === '') {
            return false;
        }

        $field = $this->safeColumn($table, $filterField);
        if (! $field) {
            return false;
        }

        $wrapped = $query->getGrammar()->wrap($field);
        $query->whereRaw('cast('.$wrapped.' as text) = ?', [$filterValue]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function empty(string $message, bool $filtered = false): array
    {
        return [
            'status' => 'empty',
            'message' => $message,
            'error' => null,
            'filtered' => $filtered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function failure(string $message): array
    {
        return [
            'status' => 'error',
            'error' => $message,
            'message' => null,
        ];
    }

    protected function unlinkedMessage(string $type): string
    {
        return match ($type) {
            'map' => 'Choose a layer or a saved map when this widget should show a map.',
            'category' => 'Choose a layer and a field when this filter should drive the dashboard.',
            default => 'Choose a layer when this widget should show data.',
        };
    }
}
