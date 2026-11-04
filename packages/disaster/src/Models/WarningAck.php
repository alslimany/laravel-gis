<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Support\Enums\WarningAckResponse;

class WarningAck extends Model
{
    protected $table = 'dm_warning_acks';

    protected $guarded = ['id'];

    protected $casts = [
        'received_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'response' => WarningAckResponse::class,
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(WarningRecipient::class, 'recipient_id');
    }

    public function isPending(): bool
    {
        return $this->acknowledged_at === null
            && $this->response === null;
    }

    public function stopsEscalation(): bool
    {
        return $this->response === WarningAckResponse::Acknowledged
            || $this->response === WarningAckResponse::Declined;
    }
}
