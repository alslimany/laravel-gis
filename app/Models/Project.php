<?php

namespace App\Models;

use App\Traits\SpatialTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use HasFactory, SpatialTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'organization_id',
        'bounding_box',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bounding_box' => 'string',
        ];
    }

    /**
     * Get the organization that owns the project.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the bounding box as WKT.
     *
     * @return string|null
     */
    public function getBoundingBoxWKTAttribute(): ?string
    {
        return $this->toWKT('bounding_box');
    }

    /**
     * Get the bounding box as GeoJSON.
     *
     * @return array|null
     */
    public function getBoundingBoxGeoJSONAttribute(): ?array
    {
        return $this->toGeoJSON('bounding_box');
    }
}
