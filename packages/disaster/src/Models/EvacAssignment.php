<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class EvacAssignment extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_evac_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'population' => 'integer',
    ];

    public function evacuationOrder(): BelongsTo
    {
        return $this->belongsTo(EvacuationOrder::class);
    }

    public function shelterSite(): BelongsTo
    {
        return $this->belongsTo(CriticalSite::class, 'shelter_site_id');
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(EvacCorridor::class, 'corridor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
