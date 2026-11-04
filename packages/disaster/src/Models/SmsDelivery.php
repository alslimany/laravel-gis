<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class SmsDelivery extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_sms_deliveries';

    protected $guarded = ['id'];

    protected $casts = [
        'attempts' => 'integer',
    ];

    public function disseminationLog(): BelongsTo
    {
        return $this->belongsTo(DisseminationLog::class, 'dissemination_log_id');
    }
}
