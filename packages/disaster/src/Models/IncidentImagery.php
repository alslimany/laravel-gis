<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class IncidentImagery extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_incident_imagery';

    protected $guarded = ['id'];

    protected $casts = [
        'layer_id' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
