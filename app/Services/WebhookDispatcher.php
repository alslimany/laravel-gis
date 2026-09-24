<?php

namespace App\Services;

use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookDispatcher
{
    public function dispatch(int $organizationId, string $event, array $payload): void
    {
        $hooks = Webhook::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get();

        foreach ($hooks as $hook) {
            $events = $hook->events ?? [];
            if (! empty($events) && ! in_array($event, $events, true) && ! in_array('*', $events, true)) {
                continue;
            }

            try {
                $body = [
                    'event' => $event,
                    'payload' => $payload,
                    'sent_at' => now()->toIso8601String(),
                ];

                $request = Http::timeout(5)->asJson();
                if ($hook->secret) {
                    $request = $request->withHeaders([
                        'X-Webhook-Signature' => hash_hmac('sha256', json_encode($body), $hook->secret),
                    ]);
                }

                $request->post($hook->url, $body);
            } catch (\Throwable $e) {
                Log::warning('Webhook dispatch failed', [
                    'webhook_id' => $hook->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
