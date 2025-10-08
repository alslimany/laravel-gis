<?php

namespace App\Models;

use App\Traits\HasGeoServerLayers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, HasGeoServerLayers;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'user_id',
    ];

    /**
     * Get the user that owns the organization.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the members of the organization.
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the projects for the organization.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get the layers for the organization.
     */
    public function layers(): HasMany
    {
        return $this->hasMany(Layer::class);
    }

    /**
     * Scope a query to only include organizations for a given user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
