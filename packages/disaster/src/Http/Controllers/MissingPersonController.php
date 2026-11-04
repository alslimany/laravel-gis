<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\MissingPerson;
use Lumina\Disaster\Services\MissingPersonsService;
use Lumina\Disaster\Support\Enums\MissingPersonStatus;
use Lumina\Disaster\Support\Enums\PersonReportType;

class MissingPersonController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request, MissingPersonsService $service): JsonResponse
    {
        Gate::authorize('disaster.registry');

        $rows = $service->listForOrganization(
            (int) $request->user()->organization_id,
            $request->user(),
            $request->ip(),
        );

        return response()->json([
            'data' => $rows->map(fn (MissingPerson $c) => $service->presentStaff($c))->all(),
        ]);
    }

    public function show(Request $request, MissingPerson $missingPerson, MissingPersonsService $service): JsonResponse
    {
        Gate::authorize('disaster.registry');
        $this->assertOrg($request, $missingPerson);

        $case = $service->findForRead(
            (int) $request->user()->organization_id,
            (int) $missingPerson->id,
            $request->user(),
            $request->ip(),
        );

        return response()->json(['data' => $service->presentStaff($case)]);
    }

    public function store(Request $request, MissingPersonsService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.registry');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validatedCase($request);

        $case = $service->openCase($organizationId, $data, $request->user(), $request->ip());

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $service->presentStaff($case)], 201);
        }

        return redirect()->route('disaster.registry.index')->with('success', 'Missing-person case opened.');
    }

    public function match(Request $request, MissingPersonsService $service): JsonResponse
    {
        Gate::authorize('disaster.registry');

        $data = $request->validate([
            'given_name' => ['nullable', 'string', 'max:128'],
            'family_name' => ['nullable', 'string', 'max:128'],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'data' => $service->suggestMatches(
                (int) $request->user()->organization_id,
                $data,
                (int) ($data['limit'] ?? 10),
            ),
        ]);
    }

    public function storeReport(Request $request, MissingPersonsService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.registry');
        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'missing_person_id' => [
                'nullable',
                'integer',
                Rule::exists('dm_missing_persons', 'id')->where('organization_id', $organizationId),
            ],
            'report_type' => ['required', Rule::enum(PersonReportType::class)],
            'reporter_ref' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $report = $service->addReport($organizationId, $data, $request->user(), $request->ip());

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'data' => [
                    'id' => $report->id,
                    'missing_person_id' => $report->missing_person_id,
                    'report_type' => $report->report_type instanceof PersonReportType
                        ? $report->report_type->value
                        : $report->report_type,
                    'created_at' => $report->created_at?->toIso8601String(),
                ],
            ], 201);
        }

        return redirect()->route('disaster.registry.index')->with('success', 'Person report recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedCase(Request $request): array
    {
        return $request->validate([
            'case_code' => ['required', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(MissingPersonStatus::class)],
            'display_name_redacted' => ['nullable', 'string', 'max:128'],
            'given_name' => ['nullable', 'string', 'max:128'],
            'family_name' => ['nullable', 'string', 'max:128'],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:120'],
            'last_seen_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'retained_until' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    protected function assertOrg(Request $request, MissingPerson $missingPerson): void
    {
        abort_unless(
            (int) $missingPerson->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
