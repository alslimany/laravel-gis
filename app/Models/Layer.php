<?php

namespace App\Models;

use App\Events\LayerCreated;
use App\Events\LayerDeleted;
use App\Events\LayerUpdated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Layer extends Model
{
    use HasFactory;

    /**
     * Storage/GeoServer identifiers that only super-admins may see in JSON/Inertia.
     *
     * @var list<string>
     */
    public const INTERNAL_NAME_ATTRIBUTES = ['table_name', 'geoserver_workspace', 'geoserver_layer_name'];

    /**
     * Hide internal storage names by default (fail closed). Super-admins get them
     * via {@see withoutInternalNamesFor()}.
     *
     * @var list<string>
     */
    protected $hidden = [
        'table_name',
        'geoserver_workspace',
        'geoserver_layer_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (Layer $layer): void {
            if (blank($layer->public_id)) {
                $layer->public_id = (string) Str::ulid();
            }

            if (blank($layer->alias) && filled($layer->organization_id)) {
                $layer->alias = static::allocateAlias(
                    (int) $layer->organization_id,
                    (string) ($layer->getAttributes()['name'] ?? $layer->table_name ?: 'layer'),
                );
            }
        });

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
        'public_id',
        'alias',
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
    /**
     * Plugin-published system layers (metadata.plugin set) carry an Arabic display name in
     * metadata.name_ar; show it in the Arabic UI. User-created layers are never renamed.
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (?string $value) {
            if (app()->getLocale() !== 'ar') {
                return $value;
            }
            $meta = $this->metadata;
            if (is_array($meta) && ! empty($meta['plugin']) && is_string($meta['name_ar'] ?? null) && $meta['name_ar'] !== '') {
                return $meta['name_ar'];
            }

            return $value;
        });
    }

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
     * SQL-view layers are computed from other data and are read-only.
     */
    /**
     * Expose internal storage names only to super-admins (diagnostics). Attributes stay
     * hidden for everyone else via {@see $hidden}.
     */
    public function withoutInternalNamesFor(?User $viewer): static
    {
        if ($viewer?->isSuperAdmin()) {
            $this->makeVisible(self::INTERNAL_NAME_ATTRIBUTES);
        }

        return $this;
    }

    /**
     * Public /api/v1 layer payloads: never emit the sequential PK (DG-1.1 / DG-0002).
     */
    public function forPublicApi(?User $viewer = null): static
    {
        $this->withoutInternalNamesFor($viewer);
        $this->makeHidden(['id']);

        return $this;
    }

    /**
     * API routes use `{layer:public_id}`: resolve by public_id or org-scoped alias.
     * Web routes keep the default primary-key binding.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== 'public_id') {
            return parent::resolveRouteBinding($value, $field);
        }

        $organizationId = auth()->user()?->organization_id;
        if ($organizationId === null) {
            abort(404);
        }

        return static::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($value) {
                $query->where('public_id', $value)
                    ->orWhere('alias', $value);
            })
            ->firstOrFail();
    }

    /**
     * GeoServer feature type name: prefer org-scoped alias when set (DG-1.1).
     * nativeName / PostGIS table remains {@see $table_name}.
     */
    public function geoServerPublishName(): string
    {
        return filled($this->alias) ? (string) $this->alias : (string) $this->table_name;
    }

    /**
     * Build a unique-per-org slug alias from a display name (or table name fallback).
     */
    public static function allocateAlias(int $organizationId, string $source, ?int $ignoreLayerId = null): string
    {
        $base = static::aliasBase($source);
        $candidate = $base;
        $n = 2;

        while (static::query()
            ->where('organization_id', $organizationId)
            ->where('alias', $candidate)
            ->when($ignoreLayerId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreLayerId))
            ->exists()) {
            $suffix = '_'.$n;
            $candidate = Str::limit($base, 63 - strlen($suffix), '').$suffix;
            $n++;
        }

        return $candidate;
    }

    public static function aliasBase(string $source): string
    {
        $slug = strtolower((string) Str::slug($source, '_'));
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?: 'layer';

        if (! preg_match('/^[a-z]/', $slug)) {
            $slug = 'l_'.$slug;
        }

        return Str::limit($slug, 50, '');
    }

    public function isSqlView(): bool
    {
        return (bool) (($this->metadata ?? [])['sql_view'] ?? false);
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
