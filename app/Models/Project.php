<?php

namespace App\Models;

use App\Traits\SpatialTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'user_id',
        'bounding_box',
        'is_public',
        'share_token',
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
            'is_public' => 'boolean',
        ];
    }

    /**
     * Boot method to auto-generate share token
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($project) {
            if (!$project->share_token) {
                $project->share_token = Str::random(32);
            }
        });
    }

    /**
     * Get the organization that owns the project.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user (owner) who created the project.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the collaborators for the project.
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the activities for this project.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->latest();
    }

    /**
     * Get the comments for this project.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class)->whereNull('parent_id')->latest();
    }

    /**
     * Get the bounding box as WKT.
     */
    public function getBoundingBoxWKTAttribute(): ?string
    {
        return $this->toWKT('bounding_box');
    }

    /**
     * Get the bounding box as GeoJSON.
     */
    public function getBoundingBoxGeoJSONAttribute(): ?array
    {
        return $this->toGeoJSON('bounding_box');
    }

    /**
     * Get the layers for this project.
     */
    public function layers()
    {
        return $this->hasMany(Layer::class);
    }

    /**
     * Scope a query to only include projects for a given organization.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $organizationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope to get public projects.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Check if a user has a specific role in this project.
     */
    public function hasUserRole(User $user, string $role): bool
    {
        return $this->collaborators()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', $role)
            ->exists();
    }

    /**
     * Check if a user can access this project.
     */
    public function userCanAccess(User $user): bool
    {
        // Organization member or collaborator
        return $this->organization_id === $user->organization_id
            || $this->collaborators()->where('user_id', $user->id)->exists();
    }

    /**
     * Log an activity for this project.
     */
    public function logActivity(string $action, ?string $description = null, ?array $properties = null, ?int $userId = null): void
    {
        $this->activities()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}
