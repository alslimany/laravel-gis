<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\SpatialTrait;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SpatialTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * Roles an organization admin may grant from user management (#66).
     */
    public const ASSIGNABLE_ROLES = ['admin', 'editor', 'viewer'];

    /**
     * organization_id is deliberately not mass assignable (#66); set it
     * explicitly (forceFill) in trusted code paths only.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'location',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'location' => 'string',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Get the organizations for the user.
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * Get the location as WKT.
     */
    public function getLocationWKTAttribute(): ?string
    {
        return $this->toWKT('location');
    }

    /**
     * Get the location as GeoJSON.
     */
    public function getLocationGeoJSONAttribute(): ?array
    {
        return $this->toGeoJSON('location');
    }

    /**
     * Get the organization that the user belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the roles for the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUser::class)
            ->withPivot('organization_id');
    }

    /**
     * Get the access groups for the user.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $roleName): bool
    {
        return $this->hasAnyRole([$roleName]);
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(array $roleNames): bool
    {
        return $this->hasRoleInOrg($roleNames, $this->organization_id);
    }

    /**
     * Check if the user holds any of the given roles within an organization.
     * A null organization only matches grants made without an organization.
     */
    public function hasRoleInOrg(array|string $roleNames, ?int $organizationId): bool
    {
        // Roles are organization roles only (#66). A user without an
        // organization holds no role, even if a legacy null-org grant exists.
        // No onboarding/org-creation route needs an exception today.
        if ($organizationId === null) {
            return false;
        }

        $roleNames = (array) $roleNames;

        // Role grants are scoped to the organization they were granted in
        // (role_user.organization_id). A user with an organization only gets
        // grants for that organization; a user without one only gets grants
        // that were made without an organization (#66 / D2).
        return $this->roles()
            ->whereIn('roles.name', $roleNames)
            ->where('role_user.organization_id', $organizationId)
            ->exists();
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Platform super admin (ADR-0001 D1). The flag is not fillable and is
     * only set by `php artisan lumina:make-super-admin`.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Get the layers created by the user.
     */
    public function layers(): HasMany
    {
        return $this->hasMany(Layer::class);
    }
}
