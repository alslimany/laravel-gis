<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayerRelationship extends Model
{
    protected $fillable = [
        'layer_id',
        'related_layer_id',
        'name',
        'foreign_key',
    ];

    /**
     * The parent layer whose features are referenced.
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    /**
     * The child layer holding the key column.
     */
    public function relatedLayer(): BelongsTo
    {
        return $this->belongsTo(Layer::class, 'related_layer_id');
    }
}
