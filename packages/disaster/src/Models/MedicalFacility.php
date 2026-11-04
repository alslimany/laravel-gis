<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class MedicalFacility extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_medical_facilities';

    protected $guarded = ['id'];

    protected $hidden = ['geom'];
}
