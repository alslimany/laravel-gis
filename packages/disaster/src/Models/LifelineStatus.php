<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;

class LifelineStatus extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_lifeline_status';

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'unknown',
    ];

    protected $casts = [
        'status' => LifelineComponentStatus::class,
        'reported_at' => 'datetime',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(LifelineComponent::class, 'component_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
