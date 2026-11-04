<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\MedicalFacility;
use Lumina\Disaster\Support\Enums\GeneratorStatus;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\GeometryWriter;

class MedicalFacilityController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => MedicalFacility::query()->forOrganization($organizationId)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $facility = MedicalFacility::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'geom' => isset($data['latitude'], $data['longitude'])
                ? GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude'])
                : null,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $facility], 201);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Medical facility created.');
    }

    public function update(Request $request, MedicalFacility $medicalFacility): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $medicalFacility);
        $data = $this->validated($request, updating: true);
        $medicalFacility->fill($data);
        if (isset($data['latitude'], $data['longitude'])) {
            $medicalFacility->geom = GeometryWriter::pointFromCoordinates($data['latitude'], $data['longitude']);
        }
        $medicalFacility->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $medicalFacility]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Medical facility updated.');
    }

    public function destroy(Request $request, MedicalFacility $medicalFacility): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $medicalFacility);
        $medicalFacility->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.assets.index')->with('success', 'Medical facility deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'facility_id' => [$updating ? 'sometimes' : 'required', 'string', 'max:64'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'bed_capacity' => ['nullable', 'integer', 'min:0'],
            'available_beds' => ['nullable', 'integer', 'min:0'],
            'trauma_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'generator_status' => ['nullable', Rule::enum(GeneratorStatus::class)],
            'resource_status' => ['nullable', Rule::enum(ResourceStatus::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    protected function assertOrg(Request $request, MedicalFacility $facility): void
    {
        abort_unless((int) $facility->organization_id === (int) $request->user()->organization_id, 404);
    }
}
