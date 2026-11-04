<?php

namespace Lumina\Disaster\Support;

use App\Support\EnumLabel;

/**
 * Localised label and sentence for an incident timeline entry. The stored body stays English
 * (audit trail); the UI shows a translated sentence built from event_type + payload.
 */
class EventLogLabel
{
    public static function type(string $eventType): string
    {
        $key = "disaster.event.{$eventType}";
        $hit = __($key);
        if ($hit !== $key) {
            return $hit;
        }
        if (str_starts_with($eventType, 'resource_request_')) {
            return __('disaster.event.resource_request_status', [
                'status' => EnumLabel::for(substr($eventType, strlen('resource_request_'))),
            ]);
        }

        return EnumLabel::for($eventType);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function body(string $eventType, ?string $body, ?array $payload): ?string
    {
        $p = $payload ?? [];
        $after = fn (string $prefix): string => $body !== null && str_starts_with($body, $prefix)
            ? substr($body, strlen($prefix))
            : (string) $body;

        $line = match (true) {
            $eventType === 'incident_opened' => __('disaster.event_body.incident_opened', ['title' => $p['title'] ?? $after('Incident opened: ')]),
            $eventType === 'incident_closed' => __('disaster.event_body.incident_closed'),
            $eventType === 'demo_seeded' => __('disaster.event_body.demo_seeded'),
            $eventType === 'task_created' => __('disaster.event_body.task_created', ['title' => $p['title'] ?? $after('Task created: ')]),
            $eventType === 'task_updated' && isset($p['task_id']) => __('disaster.event_body.task_updated', ['id' => $p['task_id']]),
            $eventType === 'task_deleted' && isset($p['task_id']) => __('disaster.event_body.task_deleted', ['id' => $p['task_id']]),
            $eventType === 'sitrep_created' && isset($p['sitrep_id']) => __('disaster.event_body.sitrep_created', ['id' => $p['sitrep_id']]),
            $eventType === 'operational_period_created' && isset($p['operational_period_id']) => __('disaster.event_body.period_created', ['id' => $p['operational_period_id']]),
            str_starts_with($eventType, 'iap_') && isset($p['version']) => __('disaster.event_body.'.$eventType, ['version' => $p['version']]),
            $eventType === 'role_assigned' && isset($p['role']) => __('disaster.event_body.role_assigned', ['role' => EnumLabel::for($p['role']), 'user' => $p['user_id'] ?? '']),
            $eventType === 'role_removed' && isset($p['role']) => __('disaster.event_body.role_removed', ['role' => EnumLabel::for($p['role'])]),
            $eventType === 'resource_requested' && isset($p['request_id']) => __('disaster.event_body.resource_requested', ['id' => $p['request_id']]),
            str_starts_with($eventType, 'resource_request_') && isset($p['request_id'], $p['from'], $p['to']) => __('disaster.event_body.resource_request_moved', [
                'id' => $p['request_id'], 'from' => EnumLabel::for($p['from']), 'to' => EnumLabel::for($p['to']),
            ]),
            default => null,
        };

        return is_string($line) && ! str_starts_with($line, 'disaster.event_body.') ? $line : $body;
    }
}
