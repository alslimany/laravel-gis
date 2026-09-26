<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisResult extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'layer_id',
        'name',
        'kind',
        'feature_count',
        'feature_ids',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'feature_count' => 'integer',
            'feature_ids' => 'array',
            'summary' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }
}
