<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Services\WarningAcknowledgementService;
use Lumina\Disaster\Support\Enums\WarningAckResponse;

class WarningAckController extends Controller
{
    use RespondsToDisasterConsole;

    public function store(
        Request $request,
        Activation $activation,
        WarningAcknowledgementService $acknowledgements,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('disaster.dispatch');
        abort_unless(
            (int) $activation->organization_id === (int) $request->user()->organization_id,
            404,
        );

        $data = $request->validate([
            'response' => ['nullable', 'string', Rule::in(array_column(WarningAckResponse::cases(), 'value'))],
        ]);

        $response = WarningAckResponse::tryFrom($data['response'] ?? WarningAckResponse::Acknowledged->value)
            ?? WarningAckResponse::Acknowledged;

        if ($response === WarningAckResponse::Escalated) {
            $response = WarningAckResponse::Acknowledged;
        }

        $ack = $acknowledgements->acknowledge($activation, $request->user(), $response);

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'data' => [
                    'id' => $ack->id,
                    'recipient_id' => $ack->recipient_id,
                    'received_at' => $ack->received_at?->toIso8601String(),
                    'acknowledged_at' => $ack->acknowledged_at?->toIso8601String(),
                    'response' => $ack->response?->value,
                ],
            ]);
        }

        return redirect()
            ->route('disaster.activations.show', $activation)
            ->with('success', 'Warning acknowledgement recorded.');
    }
}
