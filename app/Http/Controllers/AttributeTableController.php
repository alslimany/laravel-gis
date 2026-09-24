<?php

namespace App\Http\Controllers;

use App\Helpers\GeometryColumnHelper;
use App\Models\Layer;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttributeTableController extends Controller
{
    public function __construct(protected FeatureService $features) {}

    /**
     * Attribute column names from the layer table.
     */
    public function columns(Layer $layer)
    {
        $this->authorize('view', $layer);

        $columns = $layer->table_name
            ? $this->features->attributeColumns($layer->table_name)
            : [];

        return response()->json(['columns' => $columns]);
    }

    /**
     * Distinct values for one attribute column.
     */
    public function distinct(Layer $layer, string $column)
    {
        $this->authorize('view', $layer);

        return response()->json([
            'values' => $this->features->distinctValues($layer, $column),
        ]);
    }

    /**
     * Display attribute table for a layer.
     */
    public function index(Layer $layer, Request $request)
    {
        $this->authorize('view', $layer);

        $perPage = $request->input('per_page', 25);
        $search = $request->input('search');
        $shape = $request->input('shape');

        $result = $this->features->list($layer, (int) $perPage, $search, $shape);
        $features = $result['features'];
        $columns = $result['columns'];
        $fields = $layer->fields()->orderBy('sort_order')->get()->keyBy('name');
        $columnLabels = [];
        foreach ($columns as $column) {
            $columnLabels[$column] = $fields->get($column)?->alias ?: $column;
        }

        if (($request->expectsJson() || $request->is('api/*')) && ! $request->header('X-Inertia')) {
            return response()->json([
                'layer' => $layer,
                'features' => $features,
                'columns' => $columns,
            ]);
        }

        return Inertia::render('Layers/Attributes/Index', [
            'layer' => $layer,
            'features' => $features,
            'columns' => $columns,
            'columnLabels' => $columnLabels,
            'shapes' => $result['shapes'] ?? [],
            'filters' => [
                'search' => $search,
                'shape' => $shape,
            ],
            'canEdit' => $request->user()->can('update', $layer),
        ]);
    }

    /**
     * Update a feature attribute.
     */
    public function update(Layer $layer, Request $request, $featureId)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'column' => 'required|string',
            'value' => 'nullable|string',
        ]);

        $geom = GeometryColumnHelper::resolve($layer->table_name);
        $columns = $this->features->attributeColumns($layer->table_name, $geom);

        if (! in_array($validated['column'], $columns, true)) {
            return response()->json(['error' => 'Invalid column'], 400);
        }

        try {
            $this->features->update($layer, $featureId, [
                $validated['column'] => $validated['value'],
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get GeoJSON data for a layer.
     */
    public function geojson(Layer $layer)
    {
        $this->authorize('view', $layer);

        try {
            return response()->json($this->features->toGeoJson($layer));
        } catch (\Exception $e) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
