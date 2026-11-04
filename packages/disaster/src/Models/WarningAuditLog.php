<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

/**
 * Append-only audit trail for warning notify / ack / escalate events.
 */
class WarningAuditLog extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_warning_audit_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('dm_warning_audit_log is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('dm_warning_audit_log is append-only and cannot be deleted.');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(int $activationId, int $organizationId, string $eventType, array $payload = []): self
    {
        return static::query()->create([
            'activation_id' => $activationId,
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
