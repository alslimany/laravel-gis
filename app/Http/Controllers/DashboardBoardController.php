<?php

namespace App\Http\Controllers;

use App\Helpers\GeometryColumnHelper;
use App\Models\AnalysisResult;
use App\Models\DashboardBoard;
use App\Models\Layer;
use App\Models\Map;
use App\Services\ContentAccessService;
use App\Services\DashboardWidgetData;
use App\Services\DashboardWidgetDocument;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardBoardController extends Controller
{
    public function __construct(
        protected DashboardWidgetDocument $document,
        protected DashboardWidgetData $widgets
    ) {
        $this->middleware('auth')->except(['publicView', 'publicData']);
        $this->middleware('organization')->except(['publicView', 'publicData']);
    }

    public function index()
    {
        $user = Auth::user();
        $access = app(ContentAccessService::class);
        $all = DashboardBoard::where('organization_id', $user->organization_id)
            ->with('user')
            ->latest()
            ->get();
        $visible = $access->filterVisible($user, 'dashboard', $all);
        $page = max(1, (int) request('page', 1));
        $dashboards = new LengthAwarePaginator(
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
        $widgetData = $this->widgets->build($dashboard->organization_id, $widgets, $filters);

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
            'widgets' => $this->widgets->build($dashboard->organization_id, $widgets, $filters),
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
            'widgets' => $this->widgets->build($organizationId, $widgets, $filters),
        ]);
    }

    public function publicView(string $token)
    {
        $dashboard = DashboardBoard::where('share_token', $token)
            ->where('is_public', true)
            ->firstOrFail();

        [$widgets, $widgetData] = $this->publicWidgets($dashboard, request());

        return Inertia::render('Dashboards/Public', [
            'dashboard' => array_merge($dashboard->toArray(), ['widgets' => $widgets]),
            'widgetData' => $widgetData,
            'organizationName' => $dashboard->organization()->value('name'),
            'dataUrl' => route('dashboards.public.data', $token),
        ]);
    }

    public function publicData(Request $request, string $token)
    {
        $dashboard = DashboardBoard::where('share_token', $token)
            ->where('is_public', true)
            ->firstOrFail();

        [, $widgetData] = $this->publicWidgets($dashboard, $request);

        return response()->json([
            'success' => true,
            'dashboard_id' => $dashboard->id,
            'widgets' => $widgetData,
        ]);
    }

    /**
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
            'maps' => $this->mapCatalog($organizationId),
            'analyses' => $this->analysisCatalog($organizationId),
            'catalog' => $this->document->catalog(),
            'previewUrl' => route('dashboards.preview'),
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    protected function publicWidgets(DashboardBoard $dashboard, Request $request): array
    {
        $widgets = $this->document->normalize($dashboard->widgets ?? []);
        $widgetData = $this->widgets->build(
            $dashboard->organization_id,
            $widgets,
            $this->requestFilters($request),
            true,
            $dashboard->share_token,
        );

        return $this->widgets->withoutUnconfiguredMaps($widgets, $widgetData);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function mapCatalog(int $organizationId): array
    {
        return Map::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'basemap', 'is_public', 'layers'])
            ->map(fn (Map $map) => [
                'id' => $map->id,
                'name' => $map->name,
                'description' => $map->description,
                'basemap' => $map->basemap,
                'is_public' => (bool) $map->is_public,
                'layer_count' => is_array($map->layers) ? count($map->layers) : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Saved analyses are optional widget sources. An empty list is valid.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function analysisCatalog(int $organizationId): array
    {
        return AnalysisResult::query()
            ->where('organization_id', $organizationId)
            ->with('layer:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (AnalysisResult $result) => [
                'id' => $result->id,
                'name' => $result->name,
                'kind' => $result->kind,
                'layer_id' => $result->layer_id,
                'layer_name' => $result->layer?->name,
                'feature_count' => $result->feature_count,
            ])
            ->values()
            ->all();
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

        if (! app(ContentAccessService::class)->canView(
            Auth::user(),
            'dashboard',
            $dashboard->id,
            $dashboard->organization_id
        )) {
            abort(403);
        }
    }
}
