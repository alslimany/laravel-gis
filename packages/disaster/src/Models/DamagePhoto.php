<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class DamagePhoto extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $table = 'dm_damage_photos';

    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function damagePoint(): BelongsTo
    {
        return $this->belongsTo(DamagePoint::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
