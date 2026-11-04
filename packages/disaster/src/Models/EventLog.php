<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

/**
 * Append-only chronological log for ICS-lite incident operations.
 */
class EventLog extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_event_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('dm_event_log is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('dm_event_log is append-only and cannot be deleted.');
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        Incident $incident,
        string $eventType,
        ?string $body = null,
        ?int $actorUserId = null,
        array $payload = [],
    ): self {
        return static::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
            'event_type' => $eventType,
            'body' => $body,
            'actor_user_id' => $actorUserId,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
