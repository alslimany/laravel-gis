<?php

namespace Lumina\EarlyWarning\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    protected $table = 'ew_risk_assessments';

    protected $guarded = ['id'];

    protected $casts = [
        'forecast_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'score' => 'float',
        'inputs' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function basin(): BelongsTo
    {
        return $this->belongsTo(Basin::class, 'basin_code', 'basin_code');
    }
}
