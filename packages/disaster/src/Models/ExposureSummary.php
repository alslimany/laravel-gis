<?php

namespace Lumina\Disaster\Models;

use Illuminate\Database\Eloquent\Model;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;

class ExposureSummary extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_exposure_summaries';

    protected $guarded = ['id'];

    protected $casts = [
        'in_hazard' => 'boolean',
        'computed_at' => 'datetime',
        'meta' => 'array',
        'weights_snapshot' => 'array',
        'distance_m' => 'float',
        'road_length_m' => 'float',
        'hazard_level' => 'float',
        'exposure_score' => 'float',
        'vulnerability_score' => 'float',
        'risk_index' => 'float',
        'population_exposed' => 'integer',
        'building_count' => 'integer',
        'medical_facility_count' => 'integer',
        'critical_site_count' => 'integer',
        'staging_area_count' => 'integer',
        'assets_exposed' => 'integer',
        'hazard_zone_layer_id' => 'integer',
        'hazard_feature_id' => 'integer',
    ];
}
