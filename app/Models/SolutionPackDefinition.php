<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolutionPackDefinition extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'slug',
        'name',
        'description',
        'definition',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
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
}
