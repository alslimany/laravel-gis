<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class DamageFormSchema extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_damage_form_schemas';

    protected $guarded = ['id'];

    protected $casts = [
        'schema' => 'array',
        'is_default' => 'boolean',
    ];
}
