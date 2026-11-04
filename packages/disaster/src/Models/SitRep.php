<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class SitRep extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_sitreps';

    protected $guarded = ['id'];

    protected $casts = [
        'casualties' => 'array',
        'resources' => 'array',
        'lifelines_status' => 'array',
        'exported_json' => 'array',
        'prepared_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
