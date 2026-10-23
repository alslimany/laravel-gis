<?php

namespace Lumina\EarlyWarning\Models;

use Illuminate\Database\Eloquent\Model;

/** Global basin (no organization scope). */
class Basin extends Model
{
    protected $table = 'ew_basins';

    protected $guarded = ['id'];

    protected $casts = [
        'attributes' => 'array',
        'processed_at' => 'datetime',
    ];
}
