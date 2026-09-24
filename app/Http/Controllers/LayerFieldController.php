<?php

namespace App\Http\Controllers;

use App\Models\Layer;
use App\Models\LayerField;
use Illuminate\Http\Request;

class LayerFieldController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    public function index(Layer $layer)
    {
        $this->authorize('view', $layer);

        $fields = LayerField::where('layer_id', $layer->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'fields' => $fields]);
    }

    public function store(Request $request, Layer $layer)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'alias' => 'nullable|string|max:255',
            'type' => 'required|in:string,integer,float,boolean,date,datetime',
            'domain_values' => 'nullable|array',
            'required' => 'boolean',
            'calculated_expression' => 'nullable|string',
            'sort_order' => 'integer',
        ]);

        $field = LayerField::create([
            ...$validated,
            'layer_id' => $layer->id,
            'required' => $validated['required'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json(['success' => true, 'field' => $field], 201);
    }

    public function update(Request $request, Layer $layer, LayerField $field)
    {
        $this->authorize('update', $layer);

        if ($field->layer_id !== $layer->id) {
            return response()->json(['error' => 'Field does not belong to layer'], 404);
        }

        $validated = $request->validate([
            'alias' => 'nullable|string|max:255',
            'type' => 'sometimes|in:string,integer,float,boolean,date,datetime',
            'domain_values' => 'nullable|array',
            'required' => 'boolean',
            'calculated_expression' => 'nullable|string',
            'sort_order' => 'integer',
        ]);

        $field->update($validated);

        return response()->json(['success' => true, 'field' => $field->fresh()]);
    }

    public function destroy(Layer $layer, LayerField $field)
    {
        $this->authorize('update', $layer);

        if ($field->layer_id !== $layer->id) {
            return response()->json(['error' => 'Field does not belong to layer'], 404);
        }

        $field->delete();

        return response()->json(['success' => true]);
    }
}
