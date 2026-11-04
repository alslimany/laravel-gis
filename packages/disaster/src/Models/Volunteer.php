<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\VolunteerStatus;

class Volunteer extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_volunteers';

    protected $guarded = ['id'];

    protected $casts = [
        'skills' => 'array',
        'status' => VolunteerStatus::class,
    ];
}
