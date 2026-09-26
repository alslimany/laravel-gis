<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Layer;
use App\Models\Map;
use App\Services\FormSubmissionService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FormController extends Controller
{
    public function __construct(
        protected FormSubmissionService $submissions,
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
            ->withCount('submissions')
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

        return Inertia::render('Forms/Editor', [
            'layers' => $this->organizationLayers(),
        ]);
    }

    /**
     * Store a newly created form.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Form::class);

        $form = $this->persist($request);

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
        $layer = $form->layer;

        $submissions = $form->submissions()
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (FormSubmission $submission) => [
                'id' => $submission->id,
                'created_at' => $submission->created_at?->toIso8601String(),
                'feature_id' => $submission->feature_id,
                'attributes' => $submission->attributes ?? [],
                'latitude' => $submission->latitude,
                'longitude' => $submission->longitude,
                'geometry_wkt' => $submission->geometry_wkt,
                'attachment_name' => $submission->attachment_name,
            ]);

        return Inertia::render('Forms/Show', [
            'form' => $form,
            'submissions' => $submissions,
            'submissionCount' => $submissions->total(),
            'requiresGeometry' => $form->requiresGeometry(),
            'links' => [
                'attributes' => $layer ? route('layers.attributes', $layer) : null,
                'map' => $layer ? route('maps.builder', ['layer' => $layer->id]) : null,
                'export_csv' => route('forms.export.csv', $form),
                'export_excel' => route('forms.export.excel', $form),
                'layer_csv' => $layer ? route('forms.export.layer.csv', $form) : null,
                'layer_excel' => $layer ? route('forms.export.layer.excel', $form) : null,
            ],
            'maps' => $layer ? $this->mapsForLayer($layer) : [],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Form $form)
    {
        $this->authorize('update', $form);

        return Inertia::render('Forms/Editor', [
            'form' => $form,
            'layers' => $this->organizationLayers(),
        ]);
    }

    /**
     * Update the specified form.
     */
    public function update(Request $request, Form $form)
    {
        $this->authorize('update', $form);

        $this->persist($request, $form);

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
     * Download submissions collected by this form.
     */
    public function exportCsv(Form $form)
    {
        $this->authorize('view', $form);

        $table = $this->submissions->submissionTable($form);

        return $this->csvResponse($this->exportFilename($form, 'submissions', 'csv'), $table['headers'], $table['rows']);
    }

    /**
     * Download submissions collected by this form.
     */
    public function exportExcel(Form $form)
    {
        $this->authorize('view', $form);

        $table = $this->submissions->submissionTable($form);

        return $this->xlsxResponse(
            $this->exportFilename($form, 'submissions', 'xlsx'),
            $form->name.' submissions',
            $table['headers'],
            $table['rows']
        );
    }

    /**
     * Download features from the linked layer.
     */
    public function exportLayerCsv(Form $form)
    {
        $this->authorize('view', $form);

        $table = $this->layerExportTable($form);

        return $this->csvResponse($this->exportFilename($form, 'layer', 'csv'), $table['headers'], $table['rows']);
    }

    /**
     * Download features from the linked layer.
     */
    public function exportLayerExcel(Form $form)
    {
        $this->authorize('view', $form);

        $table = $this->layerExportTable($form);

        return $this->xlsxResponse(
            $this->exportFilename($form, 'layer', 'xlsx'),
            ($form->layer?->name ?: $form->name).' features',
            $table['headers'],
            $table['rows']
        );
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
            'requiresGeometry' => $form->requiresGeometry(),
        ]);
    }

    /**
     * Public form submit. Standalone forms store a submission; linked forms also write a feature.
     */
    public function publicSubmit(Request $request, string $token)
    {
        $form = Form::where('share_token', $token)
            ->where('is_public', true)
            ->with('layer')
            ->firstOrFail();

        $rules = [
            'wkt' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    try {
                        $this->submissions->acceptedAttachment($value);
                    } catch (ValidationException $e) {
                        $fail($e->errors()[$attribute][0] ?? 'This file type is not allowed.');
                    }
                },
            ],
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

        $latitude = $this->coordinate($validated['latitude'] ?? null);
        $longitude = $this->coordinate($validated['longitude'] ?? null);
        if (($latitude === null) xor ($longitude === null)) {
            return back()
                ->withInput()
                ->withErrors(['latitude' => 'Enter both latitude and longitude.']);
        }

        $wkt = $validated['wkt'] ?? null;
        if (! $wkt && $latitude !== null && $longitude !== null) {
            $wkt = sprintf('POINT(%s %s)', $this->formatCoordinate($longitude), $this->formatCoordinate($latitude));
        }

        if ($form->requiresGeometry() && ! $wkt) {
            return back()
                ->withInput()
                ->withErrors(['wkt' => 'This form needs a location. Pick a point on the map or enter latitude and longitude.']);
        }

        try {
            $result = $this->submissions->record(
                $form,
                $validated['attributes'] ?? [],
                $wkt,
                $latitude,
                $longitude,
                $request->file('attachment'),
                Auth::id()
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Form submission failed', [
                'form_id' => $form->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return back()
                ->withInput()
                ->with('error', 'Submission failed. Please try again.');
        }

        $this->webhooks->dispatch($form->organization_id, 'form.submitted', [
            'form_id' => $form->id,
            'layer_id' => $form->layer_id,
            'submission_id' => $result['submission']->id,
            'feature' => $result['feature'],
        ]);

        return redirect()
            ->route('forms.public.show', $token)
            ->with('success', 'Thank you! Your submission was recorded.');
    }

    /**
     * @return list<Layer>
     */
    protected function organizationLayers()
    {
        return Layer::where('organization_id', Auth::user()->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'geometry_type']);
    }

    protected function persist(Request $request, ?Form $form = null): Form
    {
        $layerId = $request->input('layer_id');
        $request->merge([
            'layer_id' => ($layerId === '' || $layerId === null) ? null : $layerId,
            'create_layer' => $request->boolean('create_layer'),
            'collect_geometry' => $request->boolean('collect_geometry'),
            'is_public' => $request->boolean('is_public'),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'layer_mode' => 'nullable|in:none,link,create',
            'layer_id' => 'nullable|exists:layers,id',
            'create_layer' => 'nullable|boolean',
            'collect_geometry' => 'nullable|boolean',
            'schema' => 'nullable|array',
            'schema.*.name' => 'nullable|string|max:100',
            'schema.*.label' => 'nullable|string|max:255',
            'schema.*.type' => 'nullable|string|in:text,textarea,number,select,checkbox,date',
            'schema.*.required' => 'nullable|boolean',
            'schema.*.options' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        $schema = $this->submissions->normalizeSchema($validated['schema'] ?? []);
        [$layer, $schema] = $this->resolveLayer($validated, $schema);

        $collectGeometry = (bool) ($validated['collect_geometry'] ?? false);
        if ($layer && Form::layerRequiresGeometry($layer)) {
            $collectGeometry = true;
        }

        $attributes = [
            'layer_id' => $layer?->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schema' => $schema,
            'is_public' => (bool) ($validated['is_public'] ?? false),
            'collect_geometry' => $collectGeometry,
        ];

        if ($form) {
            $form->update($attributes);

            return $form->refresh();
        }

        $user = Auth::user();

        return Form::create($attributes + [
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<array<string, mixed>>  $schema
     * @return array{0: ?Layer, 1: list<array<string, mixed>>}
     */
    protected function resolveLayer(array $validated, array $schema): array
    {
        $mode = $validated['layer_mode'] ?? null;
        $create = (bool) ($validated['create_layer'] ?? false);
        $layerId = $validated['layer_id'] ?? null;

        if ($mode === 'none') {
            $create = false;
            $layerId = null;
        } elseif ($mode === 'create') {
            $create = true;
            $layerId = null;
        } elseif ($mode === 'link') {
            $create = false;
        }

        if ($create && $layerId) {
            throw ValidationException::withMessages([
                'layer_id' => 'Link an existing layer or create one from these fields.',
            ]);
        }

        if ($create) {
            if ($schema === []) {
                throw ValidationException::withMessages([
                    'schema' => 'Add at least one field before creating a layer.',
                ]);
            }

            $created = $this->submissions->createLayerFromSchema(
                Auth::user(),
                $validated['name'],
                $validated['description'] ?? null,
                $schema,
                (bool) ($validated['collect_geometry'] ?? false)
            );

            return [$created['layer'], $created['schema']];
        }

        if ($mode === 'link' && ! $layerId) {
            throw ValidationException::withMessages([
                'layer_id' => 'Choose a layer or keep this form standalone.',
            ]);
        }

        if (! $layerId) {
            return [null, $schema];
        }

        $layer = Layer::where('organization_id', Auth::user()->organization_id)
            ->findOrFail($layerId);

        if ($schema === []) {
            $schema = $this->submissions->normalizeSchema($this->schemaFromLayerFields($layer));
        }

        return [$layer, $schema];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>}
     */
    protected function layerExportTable(Form $form): array
    {
        $layer = $form->layer;
        if (! $layer) {
            abort(404);
        }

        $table = $this->submissions->layerTable($layer);
        if ($table === null) {
            abort(404, 'Layer table is not available.');
        }

        return $table;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    protected function csvResponse(string $filename, array $headers, array $rows)
    {
        return response($this->submissions->toCsv($headers, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    protected function xlsxResponse(string $filename, string $title, array $headers, array $rows)
    {
        $tempPath = storage_path('app/tmp_'.uniqid('form_', true).'.xlsx');
        $this->submissions->saveSpreadsheet($title, $headers, $rows, $tempPath);

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    protected function exportFilename(Form $form, string $kind, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $form->name) ?: 'form';
        $base = trim((string) $base, '_');

        return ($base !== '' ? $base : 'form').'_'.$kind.'_'.now()->format('Y-m-d').'.'.$extension;
    }

    /**
     * @return list<array{id: int, name: string, url: string}>
     */
    protected function mapsForLayer(Layer $layer): array
    {
        return Map::query()
            ->where('organization_id', $layer->organization_id)
            ->get(['id', 'name', 'layers'])
            ->filter(function (Map $map) use ($layer) {
                return collect($map->layers ?? [])->contains(function ($entry) use ($layer) {
                    $id = is_array($entry) ? ($entry['id'] ?? null) : null;

                    return (int) $id === (int) $layer->id;
                });
            })
            ->map(fn (Map $map) => [
                'id' => $map->id,
                'name' => $map->name,
                'url' => route('maps.show', $map),
            ])
            ->values()
            ->all();
    }

    protected function coordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    protected function formatCoordinate(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
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
