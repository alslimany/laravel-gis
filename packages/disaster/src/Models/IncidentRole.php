<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\IncidentRoleType;

class IncidentRole extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_incident_roles';

    protected $guarded = ['id'];

    protected $casts = [
        'role' => IncidentRoleType::class,
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
