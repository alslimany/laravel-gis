<?php

namespace Lumina\DesktopConnect\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKey extends Model
{
    protected $table = 'dc_api_keys';

    protected $fillable = [
        'organization_id',
        'user_id',
        'personal_access_token_id',
        'label',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lte($at ?? now());
    }
}
