<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Volunteer;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;
use Lumina\Disaster\Support\Enums\VolunteerStatus;

class VolunteerController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize('disaster.view');

        $organizationId = (int) $request->user()->organization_id;
        $volunteers = Volunteer::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get()
            ->map(fn (Volunteer $v) => $this->present($v))
            ->all();

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['data' => $volunteers]);
        }

        return Inertia::render('Disaster/Volunteers', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'volunteers' => $volunteers,
            'status_options' => ConsoleAuth::enumOptions(VolunteerStatus::class),
            'store_url' => route('disaster.volunteers.store'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $volunteer = Volunteer::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'skills' => $this->normalizeSkills($data['skills'] ?? null),
            'availability' => $data['availability'] ?? 'available',
            'status' => $data['status'] ?? VolunteerStatus::Active,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($volunteer)], 201);
        }

        return redirect()->route('disaster.volunteers.index')->with('success', 'Volunteer registered.');
    }

    public function update(Request $request, Volunteer $volunteer): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $volunteer);
        $data = $this->validated($request, updating: true);

        if (array_key_exists('skills', $data)) {
            $data['skills'] = $this->normalizeSkills($data['skills']);
        }

        $volunteer->fill($data)->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($volunteer->fresh())]);
        }

        return redirect()->route('disaster.volunteers.index')->with('success', 'Volunteer updated.');
    }

    public function destroy(Request $request, Volunteer $volunteer): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $volunteer);
        $volunteer->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.volunteers.index')->with('success', 'Volunteer removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'skills' => ['nullable'],
            'availability' => ['nullable', 'string', 'max:64'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(VolunteerStatus::class)],
        ]);
    }

    /**
     * @return list<string>|null
     */
    protected function normalizeSkills(mixed $skills): ?array
    {
        if ($skills === null || $skills === '') {
            return null;
        }

        if (is_string($skills)) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $skills))));

            return $parts === [] ? null : $parts;
        }

        if (is_array($skills)) {
            return array_values(array_filter(array_map(
                static fn ($s) => is_string($s) ? trim($s) : null,
                $skills,
            )));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Volunteer $volunteer): array
    {
        return [
            'id' => $volunteer->id,
            'name' => $volunteer->name,
            'skills' => $volunteer->skills ?? [],
            'availability' => $volunteer->availability,
            'organization_name' => $volunteer->organization_name,
            'status' => $volunteer->status instanceof VolunteerStatus
                ? $volunteer->status->value
                : $volunteer->status,
            'update_url' => route('disaster.volunteers.update', $volunteer),
            'destroy_url' => route('disaster.volunteers.destroy', $volunteer),
        ];
    }

    protected function assertOrg(Request $request, Volunteer $volunteer): void
    {
        abort_unless(
            (int) $volunteer->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
