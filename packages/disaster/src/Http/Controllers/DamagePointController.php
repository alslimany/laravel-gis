<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\DamagePoint;
use Lumina\Disaster\Models\LifelineComponent;
use Lumina\Disaster\Services\DamageAggregationService;
use Lumina\Disaster\Services\DamageAssessmentWorkflow;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\Habitability;
use Lumina\Disaster\Support\GeometryWriter;

class DamagePointController extends Controller
{
    use RespondsToDisasterConsole;

    public function __construct(
        protected DamageAssessmentWorkflow $workflow,
        protected DamageAggregationService $aggregation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => DamagePoint::query()
                ->forOrganization($organizationId)
                ->withCount('photos')
                ->orderByDesc('assessed_at')
                ->get([
                    'id',
                    'external_id',
                    'damage_level',
                    'structure_type',
                    'habitability',
                    'hazards_present',
                    'urgent_needs',
                    'people_affected',
                    'review_status',
                    'notes',
                    'assessed_at',
                    'assessed_by',
                    'incident_id',
                    'lifeline_component_id',
                    'props',
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizeFieldOrManage();

        $data = $this->validated($request);
        $photos = $this->uploadedPhotos($request);

        $point = $this->workflow->submit($request->user(), $data, $photos);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $point], 201);
        }

        return redirect()->route('disaster.damage.index')->with('success', 'Damage assessment submitted.');
    }

    public function update(Request $request, DamagePoint $damagePoint): JsonResponse|RedirectResponse
    {
        $this->authorizeFieldOrManage();
        $this->assertOrg($request, $damagePoint);
        $data = $this->validated($request, updating: true);
        $damagePoint->fill(collect($data)->except(['latitude', 'longitude'])->all());
        if (isset($data['latitude'], $data['longitude'])) {
            $damagePoint->geom = GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude']);
        }
        $damagePoint->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $damagePoint->fresh(['photos'])]);
        }

        return redirect()->route('disaster.damage.index')->with('success', 'Damage assessment updated.');
    }

    public function destroy(Request $request, DamagePoint $damagePoint): JsonResponse|RedirectResponse
    {
        $this->authorizeFieldOrManage();
        $this->assertOrg($request, $damagePoint);
        $damagePoint->load('photos');
        $this->workflow->deleteStoredPhotos($damagePoint);
        $damagePoint->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.damage.index')->with('success', 'Damage assessment deleted.');
    }

    public function review(Request $request, DamagePoint $damagePoint): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $damagePoint);

        $data = $request->validate([
            'review_status' => ['required', Rule::enum(DamageReviewStatus::class)],
            'reviewer_comments' => ['nullable', 'string', 'max:10000'],
        ]);

        $updated = $this->workflow->transition(
            $damagePoint,
            DamageReviewStatus::from($data['review_status']),
            $request->user(),
            $data['reviewer_comments'] ?? null,
        );

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $updated]);
        }

        return redirect()->route('disaster.damage.index')->with('success', 'Damage assessment review updated.');
    }

    public function aggregates(Request $request): JsonResponse
    {
        Gate::authorize('disaster.view');
        $organizationId = (int) $request->user()->organization_id;
        $incidentId = $request->integer('incident_id') ?: null;

        return response()->json([
            'data' => $this->aggregation->aggregate($organizationId, $incidentId),
        ]);
    }

    protected function authorizeFieldOrManage(): void
    {
        abort_unless(Gate::any(['disaster.manage', 'disaster.field']), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        $organizationId = (int) $request->user()->organization_id;
        $urgentNeeds = ['water', 'shelter', 'medical', 'food'];

        $data = $request->validate([
            'external_id' => ['nullable', 'string', 'max:120'],
            'damage_level' => [$updating ? 'sometimes' : 'required', Rule::enum(DamageLevel::class)],
            'structure_type' => ['nullable', 'string', 'max:120'],
            'habitability' => ['nullable', Rule::enum(Habitability::class)],
            'hazards_present' => ['nullable', 'array'],
            'hazards_present.*' => ['string', 'max:120'],
            'urgent_needs' => ['nullable', 'array'],
            'urgent_needs.*' => ['string', Rule::in($urgentNeeds)],
            'people_affected' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'assessed_at' => ['nullable', 'date'],
            'assessed_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'props' => ['nullable', 'array'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'incident_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_incidents', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'lifeline_component_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_lifeline_components', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'form_schema_version' => ['nullable', 'string', 'max:40'],
            'photos' => ['nullable', 'array', 'max:'.DamageAssessmentWorkflow::MAX_PHOTOS],
            'photos.*' => ['file', 'image', 'max:10240'],
        ]);

        if (isset($data['lifeline_component_id'])) {
            LifelineComponent::query()
                ->forOrganization($organizationId)
                ->whereKey($data['lifeline_component_id'])
                ->firstOrFail();
        }

        return $data;
    }

    /**
     * @return list<UploadedFile>
     */
    protected function uploadedPhotos(Request $request): array
    {
        $files = $request->file('photos', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        return array_values(array_filter(
            $files,
            static fn ($file) => $file instanceof UploadedFile,
        ));
    }

    protected function assertOrg(Request $request, DamagePoint $point): void
    {
        abort_unless((int) $point->organization_id === (int) $request->user()->organization_id, 404);
    }
}
