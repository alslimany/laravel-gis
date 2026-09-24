<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayerField extends Model
{
    protected $fillable = [
        'layer_id',
        'name',
        'alias',
        'type',
        'domain_values',
        'required',
        'calculated_expression',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'domain_values' => 'array',
            'required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }
}
