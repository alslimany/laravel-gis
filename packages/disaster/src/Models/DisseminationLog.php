<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class DisseminationLog extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_dissemination_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'test_mode' => 'boolean',
        'attempt_count' => 'integer',
    ];

    public function smsDeliveries(): HasMany
    {
        return $this->hasMany(SmsDelivery::class, 'dissemination_log_id');
    }
}
