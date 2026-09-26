<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Form extends Model
{
    protected $fillable = [
        'organization_id',
        'layer_id',
        'user_id',
        'name',
        'description',
        'schema',
        'is_public',
        'collect_geometry',
        'share_token',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'is_public' => 'boolean',
            'collect_geometry' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Form $form) {
            if (! $form->share_token) {
                $form->share_token = Str::random(32);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Location is required when the form asks for one, or the linked layer stores geometry.
     */
    public function requiresGeometry(): bool
    {
        $layer = $this->layer;
        if ($layer && self::layerRequiresGeometry($layer)) {
            return true;
        }

        return (bool) $this->collect_geometry;
    }

    public static function layerRequiresGeometry(Layer $layer): bool
    {
        $type = strtolower(trim((string) $layer->geometry_type));

        return $type !== '' && ! in_array($type, ['none', 'table', 'raster', 'unknown'], true);
    }

    /**
     * One condition: another field equals or does not equal a value.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $attributes
     */
    public function fieldIsVisible(array $field, array $attributes): bool
    {
        $rule = $field['visibility'] ?? null;
        if (! is_array($rule) || empty($rule['field']) || empty($rule['operator'])) {
            return true;
        }

        $actual = self::visibilityScalar($attributes[$rule['field']] ?? null);
        $expected = self::visibilityScalar($rule['value'] ?? '');
        $matches = $actual === $expected;
        $condition = ($rule['operator'] === 'not_equals') ? ! $matches : $matches;

        return ($rule['action'] ?? 'show') === 'hide' ? ! $condition : $condition;
    }

    public static function visibilityScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
