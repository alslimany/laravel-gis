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
use Lumina\Disaster\Models\EscalationRule;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;

class EscalationRuleController extends Controller
{
    use RespondsToDisasterConsole;

    private const CHANNELS = ['email', 'telegram', 'log'];

    private const ROLES = ['admin', 'editor', 'viewer', 'dispatch'];

    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $rules = EscalationRule::query()
            ->forOrganization($organizationId)
            ->orderBy('sort_order')
            ->orderBy('timeout_minutes')
            ->orderBy('id')
            ->get()
            ->map(fn (EscalationRule $rule) => $this->present($rule))
            ->all();

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['data' => $rules]);
        }

        return Inertia::render('Disaster/EscalationRules', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'rules' => $rules,
            'channels' => self::CHANNELS,
            'roles' => self::ROLES,
            'store_url' => route('disaster.escalation-rules.store'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $rule = EscalationRule::query()->create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? 0,
            'organization_id' => $organizationId,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($rule)], 201);
        }

        return redirect()
            ->route('disaster.escalation-rules.index')
            ->with('success', 'Escalation rule created.');
    }

    public function update(Request $request, EscalationRule $escalationRule): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        abort_unless(
            (int) $escalationRule->organization_id === (int) $request->user()->organization_id,
            404,
        );

        $escalationRule->fill($this->validated($request, updating: true))->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($escalationRule)]);
        }

        return redirect()
            ->route('disaster.escalation-rules.index')
            ->with('success', 'Escalation rule updated.');
    }

    public function destroy(Request $request, EscalationRule $escalationRule): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        abort_unless(
            (int) $escalationRule->organization_id === (int) $request->user()->organization_id,
            404,
        );

        $escalationRule->delete();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()
            ->route('disaster.escalation-rules.index')
            ->with('success', 'Escalation rule deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'timeout_minutes' => [$updating ? 'sometimes' : 'required', 'integer', 'min:1', 'max:10080'],
            'next_recipient_role' => [$updating ? 'sometimes' : 'required', 'string', Rule::in(self::ROLES)],
            'channel' => [$updating ? 'sometimes' : 'required', 'string', Rule::in(self::CHANNELS)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(EscalationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'timeout_minutes' => $rule->timeout_minutes,
            'next_recipient_role' => $rule->next_recipient_role,
            'channel' => $rule->channel,
            'sort_order' => $rule->sort_order,
            'update_url' => route('disaster.escalation-rules.update', $rule),
            'destroy_url' => route('disaster.escalation-rules.destroy', $rule),
        ];
    }
}
