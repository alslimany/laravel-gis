<?php

namespace App\Http\Controllers;

use App\Helpers\GeometryColumnHelper;
use App\Models\DashboardBoard;
use App\Models\Layer;
use App\Services\DashboardWidgetDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardBoardController extends Controller
{
    public function __construct(
        protected DashboardWidgetDocument $document
    ) {
        $this->middleware('auth')->except(['publicView']);
        $this->middleware('organization')->except(['publicView']);
    }

    public function index()
    {
        $user = Auth::user();
        $access = app(\App\Services\ContentAccessService::class);
        $all = DashboardBoard::where('organization_id', $user->organization_id)
            ->with('user')
            ->latest()
            ->get();
        $visible = $access->filterVisible($user, 'dashboard', $all);
        $page = max(1, (int) request('page', 1));
        $dashboards = new \Illuminate\Pagination\LengthAwarePaginator(
            $visible->forPage($page, 15)->values(),
            $visible->count(),
            15,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return Inertia::render('Dashboards/Index', [
            'dashboards' => $dashboards,
        ]);
    }

    public function create()
    {
        return Inertia::render('Dashboards/Editor', $this->editorProps());
    }

    public function store(Request $request)
    {
        $validated = $this->validateDashboard($request);
        $validated['user_id'] = Auth::id();
        $validated['organization_id'] = Auth::user()->organization_id;
        $validated['widgets'] = $this->document->normalize($request->input('widgets'));

        $dashboard = DashboardBoard::create($validated);

        return redirect()->route('dashboards.show', $dashboard)
            ->with('success', 'Dashboard created successfully.');
    }

    public function show(DashboardBoard $dashboard)
    {
        $this->authorizeDashboard($dashboard);

        $widgets = $this->document->normalize($dashboard->widgets ?? []);
        $filters = $this->requestFilters(request());
        $widgetData = $this->buildWidgetData($dashboard->organization_id, $widgets, $filters);

        return Inertia::render('Dashboards/Show', [
            'dashboard' => array_merge($dashboard->toArray(), ['widgets' => $widgets]),
            'widgetData' => $widgetData,
            'layers' => $this->layerCatalog($dashboard->organization_id),
        ]);
    }

    public function edit(DashboardBoard $dashboard)
    {
        $this->authorizeDashboard($dashboard);

        return Inertia::render('Dashboards/Editor', $this->editorProps($dashboard));
    }

    public function update(Request $request, DashboardBoard $dashboard)
    {
        $this->authorizeDashboard($dashboard);

        $validated = $this->validateDashboard($request);
        $validated['widgets'] = $this->document->normalize($request->input('widgets'));

        $dashboard->update($validated);

        return redirect()->route('dashboards.show', $dashboard)
            ->with('success', 'Dashboard updated successfully.');
    }

    public function destroy(DashboardBoard $dashboard)
    {
        $this->authorizeDashboard($dashboard);
        $dashboard->delete();

        return redirect()->route('dashboards.index')
            ->with('success', 'Dashboard deleted.');
    }

    /**
     * Aggregate widget data for a saved dashboard (JSON).
     */
    public function data(Request $request, DashboardBoard $dashboard)
    {
        $this->authorizeDashboard($dashboard);

        $widgets = $this->document->normalize($dashboard->widgets ?? []);
        $filters = $this->requestFilters($request);

        return response()->json([
            'success' => true,
            'dashboard_id' => $dashboard->id,
            'widgets' => $this->buildWidgetData($dashboard->organization_id, $widgets, $filters),
        ]);
    }

    /**
     * Preview aggregation for an unsaved widget list.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'widgets' => 'nullable',
            'filters' => 'nullable|array',
        ]);

        $organizationId = Auth::user()->organization_id;
        $widgets = $this->document->normalize($request->input('widgets'));
        $filters = is_array($request->input('filters')) ? $request->input('filters') : [];

        return response()->json([
            'success' => true,
            'widgets' => $this->buildWidgetData($organizationId, $widgets, $filters),
        ]);
    }

    public function publicView(string $token)
    {
        $dashboard = DashboardBoard::where('share_token', $token)
            ->where('is_public', true)
            ->firstOrFail();

        $widgets = $this->document->normalize($dashboard->widgets ?? []);
        $filters = $this->requestFilters(request());
        $widgetData = $this->buildWidgetData($dashboard->organization_id, $widgets, $filters);

        return Inertia::render('Dashboards/Public', [
            'dashboard' => array_merge($dashboard->toArray(), ['widgets' => $widgets]),
            'widgetData' => $widgetData,
            'layers' => $this->layerCatalog($dashboard->organization_id),
            'dataUrl' => url('/dashboards/shared/'.$token.'/data'),
        ]);
    }

    public function publicData(Request $request, string $token)
    {
        $dashboard = DashboardBoard::where('share_token', $token)
            ->where('is_public', true)
            ->firstOrFail();

        $widgets = $this->document->normalize($dashboard->widgets ?? []);
        $filters = $this->requestFilters($request);

        return response()->json([
            'success' => true,
            'dashboard_id' => $dashboard->id,
            'widgets' => $this->buildWidgetData($dashboard->organization_id, $widgets, $filters),
        ]);
    }

    /**
     * @param  \App\Models\DashboardBoard|null  $dashboard
     * @return array<string, mixed>
     */
    protected function editorProps(?DashboardBoard $dashboard = null): array
    {
        $organizationId = Auth::user()->organization_id;
        $widgets = $dashboard
            ? $this->document->normalize($dashboard->widgets ?? [])
            : [];

        return [
            'dashboard' => $dashboard
                ? array_merge($dashboard->toArray(), ['widgets' => $widgets])
                : null,
            'layers' => $this->layerCatalog($organizationId),
            'catalog' => $this->document->catalog(),
            'previewUrl' => route('dashboards.preview'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function layerCatalog(int $organizationId): array
    {
        return Layer::where('organization_id', $organizationId)
            ->with(['fields' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(function (Layer $layer) {
                $style = is_array($layer->style_config) ? $layer->style_config : [];
                $fields = $layer->fields->map(fn ($field) => [
                    'name' => $field->name,
                    'alias' => $field->alias,
                    'type' => $field->type,
                ])->values()->all();

                if ($fields === [] && $layer->table_name && Schema::hasTable($layer->table_name)) {
                    $geom = GeometryColumnHelper::resolve($layer->table_name);
                    $fields = collect(Schema::getColumnListing($layer->table_name))
                        ->reject(fn ($column) => $column === $geom)
                        ->map(fn ($column) => [
                            'name' => $column,
                            'alias' => $column,
                            'type' => 'string',
                        ])
                        ->values()
                        ->all();
                }

                return [
                    'id' => $layer->id,
                    'name' => $layer->name,
                    'geometry_type' => $layer->geometry_type,
                    'published' => (bool) $layer->published,
                    'feature_count' => $layer->feature_count,
                    'mvt_url' => url("/api/layers/{$layer->id}/tiles/{z}/{x}/{y}.mvt"),
                    'style_config' => [
                        'fill_color' => $style['fill_color'] ?? '#06b6d4',
                        'stroke_color' => $style['stroke_color'] ?? '#dae2fd',
                        'stroke_width' => $style['stroke_width'] ?? 1,
                    ],
                    'fields' => $fields,
                ];
            })
            ->values()
            ->all();
    }

    protected function validateDashboard(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'widgets' => 'nullable',
            'is_public' => 'sometimes|boolean',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function requestFilters(Request $request): array
    {
        $filters = $request->input('filters', []);
        if (! is_array($filters)) {
            return [];
        }

        $clean = [];
        foreach ($filters as $layerId => $value) {
            if ($value === null || $value === '' || $value === '__all__') {
                continue;
            }
            $clean[(string) $layerId] = (string) $value;
        }

        return $clean;
    }

    protected function authorizeDashboard(DashboardBoard $dashboard): void
    {
        if ($dashboard->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        if (! app(\App\Services\ContentAccessService::class)->canView(
            Auth::user(),
            'dashboard',
            $dashboard->id,
            $dashboard->organization_id
        )) {
            abort(403);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @param  array<string, string>  $filters
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<string, string> layer_id => field name
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

    protected function buildWidgetData(int $organizationId, array $widgets, array $filters = []): array
    {
        $results = [];
        $categoryFields = $this->categoryFields($widgets);

        foreach ($widgets as $index => $widget) {
            $type = $widget['type'] ?? 'indicator';
            $title = $widget['title'] ?? ('Widget '.($index + 1));
            $entry = [
                'id' => $widget['id'] ?? ('w_'.$index),
                'index' => $index,
                'type' => $type,
                'title' => $title,
                'layout' => $widget['layout'] ?? null,
                'prefix' => $widget['prefix'] ?? null,
                'suffix' => $widget['suffix'] ?? null,
                'body' => $widget['body'] ?? null,
                'chart_style' => $widget['chart_style'] ?? 'bar',
                'layer_id' => $widget['layer_id'] ?? null,
                'labels' => [],
                'values' => [],
                'rows' => [],
                'value' => null,
                'options' => [],
                'map' => null,
                'error' => null,
            ];

            if ($type === 'text') {
                $results[] = $entry;
                continue;
            }

            $layerId = $widget['layer_id'] ?? null;
            if (! $layerId) {
                $entry['error'] = 'No layer configured.';
                $results[] = $entry;
                continue;
            }

            $layer = Layer::where('id', $layerId)
                ->where('organization_id', $organizationId)
                ->first();

            if (! $layer || ! Schema::hasTable($layer->table_name)) {
                $entry['error'] = 'Layer or table not found.';
                $results[] = $entry;
                continue;
            }

            try {
                $filterValue = $type === 'category' ? null : ($filters[(string) $layerId] ?? null);
                $filterField = $categoryFields[(string) $layerId] ?? null;
                $entry = array_merge($entry, $this->aggregateLayer($layer, $type, $widget, $filterValue, $filterField));
            } catch (\Throwable $e) {
                $entry['error'] = $e->getMessage();
            }

            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    protected function aggregateLayer(Layer $layer, string $type, array $widget, ?string $filterValue = null, ?string $filterField = null): array
    {
        $table = $layer->table_name;
        $geom = GeometryColumnHelper::resolve($table);
        $column = $this->safeColumn($table, $widget['column'] ?? null);
        $groupBy = $this->safeColumn($table, $widget['group_by'] ?? $column);
        $agg = strtolower((string) ($widget['aggregation'] ?? 'count'));
        $query = DB::table($table);
        $this->applyCategoryFilter($query, $table, $filterValue, $filterField);

        if ($type === 'indicator' || $type === 'kpi') {
            $value = match ($agg) {
                'sum' => $column ? (float) (clone $query)->sum($column) : null,
                'avg' => $column ? (float) (clone $query)->avg($column) : null,
                default => (int) (clone $query)->count(),
            };

            return ['value' => $value, 'labels' => [], 'values' => [], 'rows' => [], 'options' => [], 'map' => null];
        }

        if ($type === 'category') {
            $field = $groupBy ?: $column;
            if (! $field) {
                return ['value' => null, 'labels' => [], 'values' => [], 'rows' => [], 'options' => [], 'map' => null, 'error' => 'Choose a field for the filter.'];
            }

            $options = (clone $query)
                ->select($field)
                ->whereNotNull($field)
                ->distinct()
                ->orderBy($field)
                ->limit(100)
                ->pluck($field)
                ->map(fn ($v) => (string) $v)
                ->values()
                ->all();

            return [
                'value' => $filterValue,
                'labels' => $options,
                'values' => [],
                'rows' => [],
                'options' => $options,
                'map' => null,
            ];
        }

        if (in_array($type, ['serial', 'bar', 'pie', 'line'], true) && $groupBy) {
            $selectAgg = match ($agg) {
                'sum' => $column
                    ? DB::raw('SUM("'.$column.'") as value')
                    : DB::raw('COUNT(*) as value'),
                'avg' => $column
                    ? DB::raw('AVG("'.$column.'") as value')
                    : DB::raw('COUNT(*) as value'),
                default => DB::raw('COUNT(*) as value'),
            };

            $rows = (clone $query)
                ->select($groupBy, $selectAgg)
                ->groupBy($groupBy)
                ->orderByDesc('value')
                ->limit(25)
                ->get();

            return [
                'value' => null,
                'labels' => $rows->pluck($groupBy)->map(fn ($v) => (string) ($v ?? 'null'))->all(),
                'values' => $rows->pluck('value')->map(fn ($v) => (float) $v)->all(),
                'rows' => [],
                'options' => [],
                'map' => null,
            ];
        }

        if ($type === 'map') {
            $style = is_array($layer->style_config) ? $layer->style_config : [];

            return [
                'value' => null,
                'labels' => [],
                'values' => [],
                'rows' => [],
                'options' => [],
                'map' => [
                    'basemap' => $widget['basemap'] ?? 'osm',
                    'viewport' => ['center' => [0, 20], 'zoom' => 2],
                    'layers' => [[
                        'id' => $layer->id,
                        'type' => 'mvt',
                        'mvtUrl' => url("/api/layers/{$layer->id}/tiles/{z}/{x}/{y}.mvt"),
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
                    fn ($c) => $c !== $geom && $c !== 'id'
                ));
                $columns = array_slice($all, 0, 2);
                $titleField = $columns[0] ?? null;
                $descriptionField = $columns[1] ?? null;
            }
            $limit = min((int) ($widget['limit'] ?? 20), 100);
            $rows = (clone $query)->select($columns ?: ['*'])->limit($limit)->get();

            return [
                'value' => null,
                'labels' => array_values(array_filter([$titleField, $descriptionField])),
                'values' => [],
                'rows' => $rows->map(fn ($r) => [
                    'title' => $titleField ? (string) (($r->{$titleField}) ?? '') : '',
                    'description' => $descriptionField ? (string) (($r->{$descriptionField}) ?? '') : '',
                ])->all(),
                'options' => [],
                'map' => null,
            ];
        }

        // table
        $requested = is_array($widget['columns'] ?? null) ? $widget['columns'] : null;
        if ($requested) {
            $columns = array_values(array_filter(
                array_map(fn ($c) => $this->safeColumn($table, $c), $requested)
            ));
        } else {
            $columns = array_values(array_filter(
                Schema::getColumnListing($table),
                fn ($c) => $c !== $geom
            ));
        }
        if (! $columns) {
            return ['value' => null, 'labels' => [], 'values' => [], 'rows' => [], 'options' => [], 'map' => null, 'error' => 'No columns available.'];
        }

        $limit = min((int) ($widget['limit'] ?? 20), 100);
        $rows = (clone $query)->select($columns)->limit($limit)->get();

        return [
            'value' => null,
            'labels' => $columns,
            'values' => [],
            'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'options' => [],
            'map' => null,
        ];
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
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    protected function applyCategoryFilter($query, string $table, ?string $filterValue, ?string $filterField): void
    {
        if ($filterValue === null || $filterValue === '') {
            return;
        }

        $field = $this->safeColumn($table, $filterField);
        if (! $field) {
            return;
        }

        $query->where($field, $filterValue);
    }
}
