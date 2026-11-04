<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Support\EnumLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\WarningAck;
use Lumina\Disaster\Models\WarningAuditLog;
use Lumina\Disaster\Models\WarningRecipient;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;

class ActivationController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $activations = Activation::query()
            ->forOrganization($organizationId)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Activation $activation) => $this->present($activation))
            ->all();

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json(['data' => $activations]);
        }

        return Inertia::render('Disaster/Activations', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'activations' => $activations,
        ]);
    }

    public function show(Request $request, Activation $activation): Response|JsonResponse
    {
        abort_unless(
            (int) $activation->organization_id === (int) $request->user()->organization_id,
            404,
        );

        $activation->load(['warningRecipients.user', 'warningRecipients.ack']);

        $row = $this->present($activation);
        $timeline = $this->timeline($activation);
        $flags = ConsoleAuth::flags($request->user());

        if (! $request->inertia() && $request->expectsJson()) {
            return response()->json([
                'data' => $row,
                'timeline' => $timeline,
            ]);
        }

        return Inertia::render('Disaster/ActivationShow', [
            ...AdvisoryCopy::forUi(),
            ...$flags,
            'activation' => $row,
            'timeline' => $timeline,
            'index_url' => route('disaster.activations.index'),
            'acknowledge_url' => route('disaster.activations.acknowledge', $activation),
            'escalation_rules_url' => route('disaster.escalation-rules.index'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Activation $activation): array
    {
        return [
            'id' => $activation->id,
            'source' => $activation->source,
            'source_alert_id' => $activation->source_alert_id,
            'hazard_type' => $activation->hazard_type,
            'severity' => $activation->severity,
            'urgency' => $activation->urgency,
            'certainty' => $activation->certainty,
            'valid_from' => $activation->valid_from?->toIso8601String(),
            'valid_to' => $activation->valid_to?->toIso8601String(),
            'affected' => $activation->affected ?? [],
            'advisory_text' => $activation->advisory_text,
            'is_exercise' => (bool) $activation->is_exercise,
            'status' => $activation->status,
            'created_at' => $activation->created_at?->toIso8601String(),
            'show_url' => route('disaster.activations.show', $activation),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function timeline(Activation $activation): array
    {
        $events = [];

        foreach ($activation->warningRecipients as $recipient) {
            /** @var WarningRecipient $recipient */
            $ack = $recipient->ack;
            $events[] = [
                'kind' => 'recipient',
                'at' => $ack?->received_at?->toIso8601String() ?? $recipient->created_at?->toIso8601String(),
                'who' => $recipient->user?->name ?? EnumLabel::for($recipient->role ?? 'unknown'),
                'user_id' => $recipient->user_id,
                'role' => $recipient->role,
                'channel' => $recipient->channel,
                'status' => $this->ackStatus($ack),
                'response' => $ack?->response?->value,
                'acknowledged_at' => $ack?->acknowledged_at?->toIso8601String(),
            ];
        }

        $audits = WarningAuditLog::query()
            ->where('activation_id', $activation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($audits as $audit) {
            $events[] = [
                'kind' => 'audit',
                'at' => $audit->created_at?->toIso8601String(),
                'who' => null,
                'event_type' => $audit->event_type,
                'status' => $audit->event_type,
                'payload' => $audit->payload ?? [],
            ];
        }

        usort($events, function (array $a, array $b): int {
            return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        });

        return $events;
    }

    protected function ackStatus(?WarningAck $ack): string
    {
        if ($ack === null) {
            return 'pending';
        }

        if ($ack->response !== null) {
            return $ack->response->value;
        }

        return 'pending';
    }
}
