<?php

namespace Lumina\EarlyWarning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Global hourly forecast row (ADR D12). No organization_id. */
class Forecast extends Model
{
    protected $table = 'ew_forecasts';

    protected $guarded = ['id'];

    protected $casts = [
        'fetched_at' => 'datetime',
        'forecast_at' => 'datetime',
        'precipitation_mm' => 'float',
        'temperature_c' => 'float',
        'props' => 'array',
    ];

    public function basin(): BelongsTo
    {
        return $this->belongsTo(Basin::class, 'basin_code', 'basin_code');
    }
}
