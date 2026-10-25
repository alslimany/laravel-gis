<?php

namespace Lumina\EarlyWarning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Global OSM exposure rollup per basin (ADR D12). */
class ExposureSummary extends Model
{
    protected $table = 'ew_exposure_summaries';

    protected $guarded = ['id'];

    protected $casts = [
        'building_count' => 'integer',
        'road_segment_count' => 'integer',
        'road_km' => 'float',
        'bbox_south' => 'float',
        'bbox_west' => 'float',
        'bbox_north' => 'float',
        'bbox_east' => 'float',
        'fetched_at' => 'datetime',
        'props' => 'array',
    ];

    public function basin(): BelongsTo
    {
        return $this->belongsTo(Basin::class, 'basin_code', 'basin_code');
    }
}
