<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class ExchangeLog extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_exchange_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        int $organizationId,
        string $direction,
        string $messageType,
        string $payload,
        string $status,
        array $meta = [],
    ): self {
        return static::query()->create([
            'organization_id' => $organizationId,
            'direction' => $direction,
            'message_type' => $messageType,
            'payload_hash' => hash('sha256', $payload),
            'status' => $status,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
