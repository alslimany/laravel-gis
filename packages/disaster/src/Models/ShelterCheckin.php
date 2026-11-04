<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\ShelterCheckinDirection;

class ShelterCheckin extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_shelter_checkins';

    protected $guarded = ['id'];

    protected $casts = [
        'direction' => ShelterCheckinDirection::class,
        'headcount' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function shelterSite(): BelongsTo
    {
        return $this->belongsTo(CriticalSite::class, 'shelter_site_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
