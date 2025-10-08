<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Map extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'user_id',
        'organization_id',
        'viewport',
        'basemap',
        'layers',
        'is_public',
        'share_token'
    ];

    protected $casts = [
        'viewport' => 'array',
        'layers' => 'array',
        'is_public' => 'boolean'
    ];

    /**
     * Boot method to auto-generate share token
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($map) {
            if (!$map->share_token) {
                $map->share_token = Str::random(32);
            }
        });
    }

    /**
     * Get the user that owns the map
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the organization that owns the map
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope to get public maps
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope to get maps for a specific organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
