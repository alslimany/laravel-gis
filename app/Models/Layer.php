<?php

namespace App\Models;

use App\Events\LayerCreated;
use App\Events\LayerDeleted;
use App\Events\LayerUpdated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Layer extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (Layer $layer) => LayerCreated::dispatch($layer));
        static::updated(fn (Layer $layer) => LayerUpdated::dispatch($layer));
        static::deleted(fn (Layer $layer) => LayerDeleted::dispatch($layer));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'project_id',
        'user_id',
        'organization_id',
        'name',
        'description',
        'table_name',
        'geometry_type',
        'feature_count',
        'style_config',
        'geoserver_layer_name',
        'geoserver_workspace',
        'published',
        'published_at',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'style_config' => 'array',
            'metadata' => 'array',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'feature_count' => 'integer',
        ];
    }

    /**
     * Get the project that owns the layer.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user (creator) that owns the layer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the organization that owns the layer.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Field registry for this layer.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(LayerField::class)->orderBy('sort_order');
    }

    /**
     * Relationships where this layer is the parent.
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(LayerRelationship::class);
    }

    /**
     * Check if layer is published to GeoServer.
     */
    public function isPublished(): bool
    {
        return $this->published;
    }

    /**
     * Mark layer as published.
     */
    public function markAsPublished(string $layerName, string $workspace): void
    {
        $this->update([
            'published' => true,
            'geoserver_layer_name' => $layerName,
            'geoserver_workspace' => $workspace,
            'published_at' => now(),
        ]);
    }

    /**
     * Mark layer as unpublished.
     */
    public function markAsUnpublished(): void
    {
        $this->update([
            'published' => false,
            'geoserver_layer_name' => null,
            'geoserver_workspace' => null,
            'published_at' => null,
        ]);
    }

    /**
     * Scope a query to only include published layers.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    /**
     * Scope a query to only include layers for a given organization.
     *
     * @param  Builder  $query
     * @param  int  $organizationId
     * @return Builder
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
