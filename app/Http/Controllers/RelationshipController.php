<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Models\LayerRelationship;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RelationshipController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Relationships for a layer as JSON (map workspace inspector).
     */
    public function index(Layer $layer)
    {
        $this->authorize('view', $layer);

        $relationships = $layer->relationships()
            ->with('relatedLayer:id,name,table_name,geometry_type')
            ->get();

        return response()->json(['success' => true, 'relationships' => $relationships]);
    }

    /**
     * Inertia page for managing a layer's relationships.
     */
    public function page(Layer $layer)
    {
        $this->authorize('view', $layer);

        return Inertia::render('Layers/Relationships', [
            'layer' => $layer,
            'relationships' => $layer->relationships()->with('relatedLayer:id,name,table_name')->get(),
        ]);
    }

    /**
     * Organization-wide relationship builder (drag-to-connect canvas).
     */
    public function builder(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $featureService = app(FeatureService::class);

        $layers = Layer::where('organization_id', $organizationId)
            ->whereNotNull('table_name')
            ->orderBy('name')
            ->get(['id', 'name', 'table_name', 'geometry_type', 'feature_count'])
            ->map(fn (Layer $layer) => [
                'id' => $layer->id,
                'name' => $layer->name,
                'table_name' => $layer->table_name,
                'geometry_type' => $layer->geometry_type,
                'feature_count' => $layer->feature_count,
                'key_stem' => Str::singular(Str::snake($layer->name)),
                'columns' => $featureService->attributeColumns($layer->table_name),
            ])
            ->values();

        $relationships = LayerRelationship::whereHas('layer', fn ($query) => $query->where('organization_id', $organizationId))
            ->with(['layer:id,name', 'relatedLayer:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (LayerRelationship $relationship) => [
                'id' => $relationship->id,
                'name' => $relationship->name,
                'foreign_key' => $relationship->foreign_key,
                'layer_id' => $relationship->layer_id,
                'related_layer_id' => $relationship->related_layer_id,
                'parent_name' => $relationship->layer?->name,
                'child_name' => $relationship->relatedLayer?->name,
            ])
            ->values();

        return Inertia::render('Relationships/Builder', [
            'layers' => $layers,
            'relationships' => $relationships,
        ]);
    }

    public function store(Request $request, Layer $layer)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('layer_relationships', 'name')->where('layer_id', $layer->id),
            ],
            'related_layer_id' => [
                'required',
                'integer',
                Rule::exists('layers', 'id')->where('organization_id', $layer->organization_id),
            ],
            'foreign_key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
                Rule::notIn(['id']),
                Rule::unique('layer_relationships', 'foreign_key')
                    ->where('layer_id', $layer->id)
                    ->where('related_layer_id', $request->input('related_layer_id')),
            ],
        ]);

        if ((int) $validated['related_layer_id'] === $layer->id) {
            throw ValidationException::withMessages([
                'related_layer_id' => 'A layer cannot relate to itself.',
            ]);
        }

        $related = Layer::findOrFail($validated['related_layer_id']);
        if (! $related->table_name) {
            throw ValidationException::withMessages([
                'related_layer_id' => 'The related layer has no table to hold the key column.',
            ]);
        }

        $this->ensureKeyColumn($related, $validated['foreign_key']);

        $relationship = LayerRelationship::create([
            'layer_id' => $layer->id,
            'related_layer_id' => $related->id,
            'name' => $validated['name'],
            'foreign_key' => $validated['foreign_key'],
        ]);

        return response()->json([
            'success' => true,
            'relationship' => $relationship->load('relatedLayer:id,name,table_name'),
        ], 201);
    }

    /**
     * Child features whose key column matches a parent feature id.
     */
    public function features(Request $request, Layer $layer, LayerRelationship $relationship)
    {
        $this->authorize('view', $layer);

        if ($relationship->layer_id !== $layer->id) {
            return response()->json(['error' => 'Relationship not found'], 404);
        }

        $validated = $request->validate([
            'parent_id' => 'required|integer',
        ]);

        $related = $relationship->relatedLayer;
        if (! $related) {
            return response()->json(['error' => 'Related layer not found'], 404);
        }

        $this->authorize('view', $related);

        if (! $related->table_name || ! Schema::hasColumn($related->table_name, $relationship->foreign_key)) {
            return response()->json(['columns' => ['id'], 'features' => []]);
        }

        $columns = app(FeatureService::class)->attributeColumns($related->table_name);
        $rows = DB::table($related->table_name)
            ->where($relationship->foreign_key, (string) $validated['parent_id'])
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json([
            'columns' => array_merge(['id'], $columns),
            'features' => $rows->map(fn ($row) => (array) $row)->all(),
        ]);
    }

    public function destroy(Layer $layer, LayerRelationship $relationship)
    {
        $this->authorize('update', $layer);

        if ($relationship->layer_id !== $layer->id) {
            return response()->json(['error' => 'Relationship not found'], 404);
        }

        $relationship->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Create the key column on the child table when it does not exist yet.
     */
    protected function ensureKeyColumn(Layer $related, string $foreignKey): void
    {
        if (Schema::hasColumn($related->table_name, $foreignKey)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $columnType = $driver === 'pgsql' ? 'BIGINT' : 'INTEGER';

        DB::statement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteIdent($related->table_name),
            $this->quoteIdent($foreignKey),
            $columnType
        ));
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }
}
