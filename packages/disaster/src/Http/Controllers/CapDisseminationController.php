<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Services\CapDisseminationService;

class CapDisseminationController extends Controller
{
    use RespondsToDisasterConsole;

    public function store(Request $request, CapDisseminationService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.public_publish');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event' => ['nullable', 'string', 'max:120'],
        ]);

        $log = $service->draftCap($organizationId, $data);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $log], 201);
        }

        return redirect()
            ->route('disaster.index')
            ->with('success', 'CAP draft logged (channel=cap, id='.$log->id.').');
    }

    public function publish(Request $request, CapDisseminationService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.public_publish');

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validatedPublish($request);
        $testMode = (bool) ($data['test_mode'] ?? false);
        unset($data['test_mode']);

        $log = $service->publish($organizationId, $data, $testMode);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($log, $service)], 201);
        }

        return redirect()
            ->route('disaster.index')
            ->with('success', 'CAP '.($testMode ? 'test alert' : 'alert').' queued (id='.$log->id.').');
    }

    public function update(
        Request $request,
        DisseminationLog $disseminationLog,
        CapDisseminationService $service,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('disaster.public_publish');
        $this->assertOrg($request, $disseminationLog);

        $data = $this->validatedPublish($request);
        $testMode = (bool) ($data['test_mode'] ?? false);
        unset($data['test_mode']);

        $log = $service->update((int) $request->user()->organization_id, $disseminationLog, $data, $testMode);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($log, $service)], 201);
        }

        return redirect()
            ->route('disaster.index')
            ->with('success', 'CAP update queued (id='.$log->id.').');
    }

    public function cancel(
        Request $request,
        DisseminationLog $disseminationLog,
        CapDisseminationService $service,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('disaster.public_publish');
        $this->assertOrg($request, $disseminationLog);

        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event' => ['nullable', 'string', 'max:120'],
            'hazard_type' => ['nullable', 'string', 'max:64'],
            'test_mode' => ['sometimes', 'boolean'],
            'sms_recipients' => ['sometimes', 'array'],
            'sms_recipients.*' => ['string', 'max:64'],
            'sms_body' => ['nullable', 'string', 'max:480'],
        ]);
        $testMode = (bool) ($data['test_mode'] ?? false);
        unset($data['test_mode']);

        $log = $service->cancel((int) $request->user()->organization_id, $disseminationLog, $data, $testMode);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($log, $service)], 201);
        }

        return redirect()
            ->route('disaster.index')
            ->with('success', 'CAP cancel queued (id='.$log->id.').');
    }

    public function report(
        Request $request,
        DisseminationLog $disseminationLog,
        CapDisseminationService $service,
    ): JsonResponse {
        Gate::authorize('disaster.public_publish');
        $this->assertOrg($request, $disseminationLog);

        $disseminationLog->load('smsDeliveries');

        return response()->json([
            'data' => [
                'log' => $this->present($disseminationLog, $service),
                'deliveries' => $service->deliveryReport($disseminationLog),
                'sms' => $disseminationLog->smsDeliveries,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPublish(Request $request): array
    {
        return $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'headline_ar' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'event' => ['nullable', 'string', 'max:120'],
            'hazard_type' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:64'],
            'urgency' => ['nullable', 'string', 'max:32'],
            'severity' => ['nullable', 'string', 'max:32'],
            'certainty' => ['nullable', 'string', 'max:32'],
            'expires' => ['nullable', 'date'],
            'area_desc' => ['nullable', 'string', 'max:255'],
            'polygon' => ['nullable', 'array', 'min:3'],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.0' => ['numeric'],
            'polygon.*.1' => ['numeric'],
            'scope' => ['nullable', 'string', Rule::in(['Public', 'Restricted', 'Private'])],
            'test_mode' => ['sometimes', 'boolean'],
            'sms_recipients' => ['sometimes', 'array'],
            'sms_recipients.*' => ['string', 'max:64'],
            'sms_body' => ['nullable', 'string', 'max:480'],
            'fan_out_radio' => ['sometimes', 'boolean'],
        ]);
    }

    protected function assertOrg(Request $request, DisseminationLog $log): void
    {
        abort_unless(
            (int) $log->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(DisseminationLog $log, CapDisseminationService $service): array
    {
        $log->loadMissing('smsDeliveries');

        return [
            'id' => $log->id,
            'channel' => $log->channel,
            'status' => $log->status,
            'msg_type' => $log->msg_type,
            'identifier' => $log->identifier,
            'references_identifier' => $log->references_identifier,
            'outcome' => $log->outcome,
            'test_mode' => (bool) $log->test_mode,
            'attempt_count' => (int) $log->attempt_count,
            'created_at' => $log->created_at?->toIso8601String(),
            'deliveries' => $service->deliveryReport($log),
            'payload' => $log->payload,
        ];
    }
}
