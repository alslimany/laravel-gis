<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardBoard extends Model
{
    protected $table = 'dashboards';

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'description',
        'widgets',
        'is_public',
        'share_token',
    ];

    protected function casts(): array
    {
        return [
            'widgets' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (DashboardBoard $dashboard) {
            if (! $dashboard->share_token) {
                $dashboard->share_token = Str::random(32);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
