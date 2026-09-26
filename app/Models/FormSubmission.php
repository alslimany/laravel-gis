<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmission extends Model
{
    protected $fillable = [
        'form_id',
        'organization_id',
        'layer_id',
        'feature_id',
        'user_id',
        'attributes',
        'geometry_wkt',
        'latitude',
        'longitude',
        'attachment_name',
        'attachment_path',
        'attachment_mime',
        'attachment_size',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'feature_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'attachment_size' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
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
}
