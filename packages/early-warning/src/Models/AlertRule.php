<?php

namespace Lumina\EarlyWarning\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    protected $table = 'ew_alert_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'threshold' => 'float',
        'channels' => 'array',
        'enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AlertLog::class, 'alert_rule_id');
    }
}
