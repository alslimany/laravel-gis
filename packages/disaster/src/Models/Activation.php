<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class Activation extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_activations';

    protected $guarded = ['id'];

    protected $casts = [
        'affected' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_exercise' => 'boolean',
    ];

    public function warningRecipients(): HasMany
    {
        return $this->hasMany(WarningRecipient::class);
    }

    public function warningAuditLogs(): HasMany
    {
        return $this->hasMany(WarningAuditLog::class);
    }

    public function evacuationOrders(): HasMany
    {
        return $this->hasMany(EvacuationOrder::class);
    }
}
