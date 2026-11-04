<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class WarningRecipient extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_warning_recipients';

    protected $guarded = ['id'];

    public function activation(): BelongsTo
    {
        return $this->belongsTo(Activation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ack(): HasOne
    {
        return $this->hasOne(WarningAck::class, 'recipient_id');
    }
}
