<?php

namespace Lumina\Disaster\Http\Controllers;

use App\Plugins\PluginManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\Incident;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Services\ShelterOccupancyService;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\Dissemination\CapChannelAdapter;
use Lumina\Disaster\Support\Enums\DisseminationStatus;

/** Token-gated public advisory feed (no AVL or live unit positions). */
class PublicPortalController extends Controller
{
    public function show(string $token): Response
    {
        $access = $this->resolve($token);

        return Inertia::render('Disaster/Public', [
            ...AdvisoryCopy::forUi(),
            'token' => $token,
            'feed_url' => route('disaster.public.feed', ['token' => $token]),
            ...$this->payload($access),
        ]);
    }

    public function feed(string $token): JsonResponse
    {
        $access = $this->resolve($token);

        return response()->json([
            'plugin' => 'disaster',
            ...AdvisoryCopy::forUi(),
            'generated_at' => now()->utc()->toIso8601String(),
            ...$this->payload($access),
        ]);
    }

    /**
     * Public shelter occupancy aggregates only — no personal registry data.
     */
    public function shelterOccupancy(string $token, ShelterOccupancyService $occupancy): JsonResponse
    {
        $access = $this->resolve($token);

        return response()->json([
            'plugin' => 'disaster',
            'generated_at' => now()->utc()->toIso8601String(),
            'shelter_occupancy' => $occupancy->publicAggregates((int) $access->organization_id),
        ]);
    }

    protected function resolve(string $token): PublicToken
    {
        $access = PublicToken::findActiveByPlain($token);
        abort_if($access === null, 404);

        $plugins = app(PluginManager::class);
        abort_unless($plugins->isInstalled('disaster'), 404);
        abort_unless($plugins->isEnabledFor('disaster', $access->organization_id), 404);
        abort_unless($access->allows('feed'), 403);

        $access->touchUsed();

        return $access;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(PublicToken $access): array
    {
        $openIncidents = Incident::query()
            ->where('organization_id', $access->organization_id)
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->limit(10)
            ->get(['external_id', 'title', 'incident_type', 'priority', 'opened_at']);

        return [
            'open_incidents' => $openIncidents,
            'avl_included' => false,
            'shelter_occupancy' => app(ShelterOccupancyService::class)
                ->publicAggregates((int) $access->organization_id),
            'cap' => $this->latestCap((int) $access->organization_id),
        ];
    }

    /**
     * Serve last public CAP from cache under high load (Annex B.3).
     *
     * @return array{identifier: string|null, msg_type: string|null, cap_xml: string|null, sent_at: string|null}|null
     */
    protected function latestCap(int $organizationId): ?array
    {
        $ttl = (int) config('disaster.cap.public_cache_seconds', 60);

        /** @var array{identifier: string|null, msg_type: string|null, cap_xml: string|null, sent_at: string|null}|null $cached */
        $cached = Cache::remember(
            CapChannelAdapter::cacheKey($organizationId),
            $ttl,
            function () use ($organizationId): ?array {
                $log = DisseminationLog::query()
                    ->forOrganization($organizationId)
                    ->where('channel', 'cap')
                    ->where('test_mode', false)
                    ->whereIn('status', [
                        DisseminationStatus::Sent->value,
                        DisseminationStatus::Delivered->value,
                    ])
                    ->whereNotNull('identifier')
                    ->orderByDesc('id')
                    ->first();

                if ($log === null) {
                    return null;
                }

                $payload = is_array($log->payload) ? $log->payload : [];

                return [
                    'identifier' => $log->identifier,
                    'msg_type' => $log->msg_type,
                    'cap_xml' => $payload['cap_xml'] ?? null,
                    'sent_at' => $log->created_at?->utc()->toIso8601String(),
                ];
            },
        );

        return $cached;
    }
}
