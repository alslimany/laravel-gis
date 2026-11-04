<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class EscalationRule extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_escalation_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'timeout_minutes' => 'integer',
        'sort_order' => 'integer',
    ];
}
