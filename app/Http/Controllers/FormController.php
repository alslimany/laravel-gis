<?php

namespace App\Http\Controllers;

use App\Models\FeatureAttachment;
use App\Models\Form;
use App\Models\Layer;
use App\Services\FeatureService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class FormController extends Controller
{
    public function __construct(
        protected FeatureService $features,
        protected WebhookDispatcher $webhooks
    ) {
        $this->middleware(['auth', 'organization'])->except(['publicShow', 'publicSubmit']);
    }

    /**
     * Display a listing of organization forms.
     */
    public function index()
    {
        $this->authorize('viewAny', Form::class);

        $forms = Form::where('organization_id', Auth::user()->organization_id)
            ->with(['layer', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('Forms/Index', [
            'forms' => $forms,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', Form::class);

        $layers = Layer::where('organization_id', Auth::user()->organization_id)
            ->orderBy('name')
            ->get();

        return Inertia::render('Forms/Editor', [
            'layers' => $layers,
        ]);
    }

    /**
     * Store a newly created form.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Form::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'layer_id' => 'required|exists:layers,id',
            'schema' => 'nullable|array',
            'schema.*.name' => 'nullable|string|max:100',
            'schema.*.label' => 'nullable|string|max:255',
            'schema.*.type' => 'nullable|string|in:text,textarea,number,select,checkbox,date',
            'schema.*.required' => 'nullable|boolean',
            'schema.*.options' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        $user = Auth::user();
        $layer = Layer::where('organization_id', $user->organization_id)
            ->findOrFail($validated['layer_id']);

        $schema = $validated['schema'] ?? [];
        if ($schema === [] || $this->schemaLooksEmpty($schema)) {
            $schema = $this->schemaFromLayerFields($layer);
        }

        $form = Form::create([
            'organization_id' => $user->organization_id,
            'layer_id' => $layer->id,
            'user_id' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schema' => $this->normalizeSchema($schema),
            'is_public' => (bool) ($validated['is_public'] ?? false),
        ]);

        return redirect()
            ->route('forms.show', $form)
            ->with('success', 'Form created successfully.');
    }

    /**
     * Display the specified form.
     */
    public function show(Form $form)
    {
        $this->authorize('view', $form);

        $form->load(['layer', 'user', 'organization']);

        return Inertia::render('Forms/Show', [
            'form' => $form,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Form $form)
    {
        $this->authorize('update', $form);

        $layers = Layer::where('organization_id', Auth::user()->organization_id)
            ->orderBy('name')
            ->get();

        return Inertia::render('Forms/Editor', [
            'form' => $form,
            'layers' => $layers,
        ]);
    }

    /**
     * Update the specified form.
     */
    public function update(Request $request, Form $form)
    {
        $this->authorize('update', $form);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'layer_id' => 'required|exists:layers,id',
            'schema' => 'nullable|array',
            'schema.*.name' => 'nullable|string|max:100',
            'schema.*.label' => 'nullable|string|max:255',
            'schema.*.type' => 'nullable|string|in:text,textarea,number,select,checkbox,date',
            'schema.*.required' => 'nullable|boolean',
            'schema.*.options' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        $layer = Layer::where('organization_id', Auth::user()->organization_id)
            ->findOrFail($validated['layer_id']);

        $form->update([
            'layer_id' => $layer->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schema' => $this->normalizeSchema($validated['schema'] ?? []),
            'is_public' => (bool) ($validated['is_public'] ?? false),
        ]);

        return redirect()
            ->route('forms.show', $form)
            ->with('success', 'Form updated successfully.');
    }

    /**
     * Remove the specified form.
     */
    public function destroy(Form $form)
    {
        $this->authorize('delete', $form);

        $form->delete();

        return redirect()
            ->route('forms.index')
            ->with('success', 'Form deleted successfully.');
    }

    /**
     * Public form fill page (by share token).
     */
    public function publicShow(string $token)
    {
        $form = Form::where('share_token', $token)
            ->where('is_public', true)
            ->with('layer')
            ->firstOrFail();

        return Inertia::render('Forms/Public', [
            'form' => $form,
        ]);
    }

    /**
     * Public form submit — creates a feature (+ optional attachment).
     */
    public function publicSubmit(Request $request, string $token)
    {
        $form = Form::where('share_token', $token)
            ->where('is_public', true)
            ->with('layer')
            ->firstOrFail();

        $layer = $form->layer;
        if (! $layer) {
            return back()->with('error', 'This form is not linked to a valid layer.');
        }

        $rules = [
            'wkt' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'attachment' => 'nullable|file|max:10240',
            'attributes' => 'nullable|array',
        ];

        foreach ($form->schema ?? [] as $field) {
            $name = $field['name'] ?? null;
            if (! $name) {
                continue;
            }
            $fieldRules = [];
            if (! empty($field['required'])) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            $type = $field['type'] ?? 'text';
            $fieldRules[] = match ($type) {
                'number' => 'numeric',
                'checkbox' => 'boolean',
                'date' => 'date',
                default => 'string',
            };

            $rules["attributes.{$name}"] = $fieldRules;
        }

        $validated = $request->validate($rules);

        $wkt = $validated['wkt'] ?? null;
        if (! $wkt && isset($validated['latitude'], $validated['longitude'])) {
            $wkt = sprintf('POINT(%s %s)', $validated['longitude'], $validated['latitude']);
        }

        if (! $wkt) {
            return back()
                ->withInput()
                ->withErrors(['wkt' => 'Please provide a location (coordinates or WKT).']);
        }

        try {
            $feature = $this->features->create(
                $layer,
                $validated['attributes'] ?? [],
                $wkt
            );

            $featureId = $feature['id'] ?? ($feature['properties']['id'] ?? null);

            if ($request->hasFile('attachment') && $featureId) {
                $file = $request->file('attachment');
                $directory = "attachments/{$layer->id}/{$featureId}";
                $storedName = Str::uuid().'_'.$file->getClientOriginalName();
                $path = $file->storeAs($directory, $storedName, 'local');

                FeatureAttachment::create([
                    'layer_id' => $layer->id,
                    'feature_id' => (int) $featureId,
                    'user_id' => Auth::id(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            $this->webhooks->dispatch($form->organization_id, 'form.submitted', [
                'form_id' => $form->id,
                'layer_id' => $layer->id,
                'feature' => $feature,
            ]);

            return redirect()
                ->route('forms.public.show', $token)
                ->with('success', 'Thank you! Your submission was recorded.');
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', 'Submission failed: '.$e->getMessage());
        }
    }

    /**
     * Normalize schema field definitions.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeSchema(array $schema): array
    {
        return array_values(array_filter(array_map(function ($field) {
            if (! is_array($field) || empty($field['name'])) {
                return null;
            }

            return [
                'name' => preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $field['name']),
                'label' => $field['label'] ?? $field['name'],
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
                'options' => $field['options'] ?? [],
            ];
        }, $schema)));
    }

    protected function schemaLooksEmpty(array $schema): bool
    {
        foreach ($schema as $field) {
            if (is_array($field) && ! empty($field['name'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function schemaFromLayerFields(Layer $layer): array
    {
        return $layer->fields()
            ->orderBy('sort_order')
            ->get()
            ->map(function ($field) {
                $type = match ($field->type) {
                    'number', 'integer', 'float', 'double' => 'number',
                    'boolean' => 'checkbox',
                    'date', 'datetime', 'timestamp' => 'date',
                    default => ! empty($field->domain_values) ? 'select' : 'text',
                };

                return [
                    'name' => $field->name,
                    'label' => $field->alias ?: $field->name,
                    'type' => $type,
                    'required' => (bool) $field->required,
                    'options' => $field->domain_values ?? [],
                ];
            })
            ->all();
    }
}
