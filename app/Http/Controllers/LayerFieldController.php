<?php

namespace App\Http\Controllers;

use App\Helpers\GeometryColumnHelper;
use App\Models\Layer;
use App\Models\LayerField;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

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

    /**
     * Inertia page for managing a layer's field definitions.
     */
    public function page(Layer $layer)
    {
        $this->authorize('view', $layer);

        return Inertia::render('Layers/Fields', [
            // Internal table names are for super-admins only.
            'layer' => $layer->withoutInternalNamesFor(request()->user()),
            'readOnly' => $layer->isSqlView(),
            'fields' => $layer->fields()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, Layer $layer)
    {
        $this->authorize('update', $layer);
        // SQL-view layers: no ALTER TABLE / field changes (the view is rebuilt on publish).
        app(FeatureService::class)->assertWritable($layer);

        $geom = $layer->table_name ? GeometryColumnHelper::resolve($layer->table_name) : 'geom';

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
                Rule::notIn(['id', $geom]),
                Rule::unique('layer_fields', 'name')->where('layer_id', $layer->id),
            ],
            'alias' => 'nullable|string|max:255',
            'type' => 'required|in:string,integer,float,number,boolean,date,datetime',
            'domain_values' => 'nullable|array',
            'required' => 'boolean',
            'calculated_expression' => 'nullable|string|max:255',
            'sort_order' => 'integer',
        ]);

        $this->guardExpression($layer, $validated['calculated_expression'] ?? null, $validated['name']);

        $this->ensureColumn($layer, $validated['name'], $validated['type']);

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
        // SQL-view layers: no ALTER TABLE / field changes (the view is rebuilt on publish).
        app(FeatureService::class)->assertWritable($layer);

        if ($field->layer_id !== $layer->id) {
            return response()->json(['error' => 'Field does not belong to layer'], 404);
        }

        $validated = $request->validate([
            'alias' => 'nullable|string|max:255',
            'type' => 'sometimes|in:string,integer,float,number,boolean,date,datetime',
            'domain_values' => 'nullable|array',
            'required' => 'boolean',
            'calculated_expression' => 'nullable|string|max:255',
            'sort_order' => 'integer',
        ]);

        $this->guardExpression($layer, $validated['calculated_expression'] ?? null, $field->name);

        $field->update($validated);

        return response()->json(['success' => true, 'field' => $field->fresh()]);
    }

    public function destroy(Layer $layer, LayerField $field)
    {
        $this->authorize('update', $layer);
        // SQL-view layers: no ALTER TABLE / field changes (the view is rebuilt on publish).
        app(FeatureService::class)->assertWritable($layer);

        if ($field->layer_id !== $layer->id) {
            return response()->json(['error' => 'Field does not belong to layer'], 404);
        }

        $field->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Reject expressions with disallowed syntax or unknown field references.
     */
    protected function guardExpression(Layer $layer, ?string $expression, ?string $excludeName): void
    {
        $expression = trim((string) $expression);
        if ($expression === '') {
            return;
        }

        if (! preg_match('/^[a-zA-Z0-9_\s\+\-\*\/\(\)\.]+$/', $expression)) {
            throw ValidationException::withMessages([
                'calculated_expression' => 'Expressions may only contain field names, numbers, and + - * / ( ).',
            ]);
        }

        preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $expression, $matches);
        $tokens = array_values(array_unique($matches[0]));

        if ($tokens === []) {
            throw ValidationException::withMessages([
                'calculated_expression' => 'Reference at least one field, for example depth * 2.',
            ]);
        }

        $known = $layer->fields()->pluck('name')->all();
        if ($layer->table_name) {
            $known = array_merge($known, app(FeatureService::class)->attributeColumns($layer->table_name));
        }
        if ($excludeName !== null) {
            $known = array_diff($known, [$excludeName]);
        }
        $known = array_values(array_unique($known));

        foreach ($tokens as $token) {
            if (! in_array($token, $known, true)) {
                throw ValidationException::withMessages([
                    'calculated_expression' => "Unknown field: {$token}.",
                ]);
            }
        }
    }

    /**
     * Create the backing column when a new field points at a table that lacks it.
     */
    protected function ensureColumn(Layer $layer, string $name, string $type): void
    {
        if (! $layer->table_name || Schema::hasColumn($layer->table_name, $name)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $columnType = match ($type) {
            'integer' => $driver === 'pgsql' ? 'BIGINT' : 'INTEGER',
            'float', 'number' => $driver === 'pgsql' ? 'DOUBLE PRECISION' : 'NUMERIC',
            'boolean' => 'BOOLEAN',
            'date' => $driver === 'pgsql' ? 'DATE' : 'TEXT',
            'datetime', 'timestamp' => $driver === 'pgsql' ? 'TIMESTAMP' : 'TEXT',
            default => 'TEXT',
        };

        DB::statement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteIdent($layer->table_name),
            $this->quoteIdent($name),
            $columnType
        ));
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }
}
