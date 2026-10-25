<?php

namespace Lumina\EarlyWarning\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PublicToken extends Model
{
    protected $table = 'ew_public_tokens';

    protected $guarded = ['id'];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function allows(string $ability): bool
    {
        $abilities = $this->abilities ?? ['feed', 'report'];

        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }

    /**
     * @return array{plain: string, model: self}
     */
    public static function issue(int $organizationId, ?string $label = null, array $abilities = ['feed', 'report']): array
    {
        $plain = Str::random(48);
        $model = static::query()->create([
            'organization_id' => $organizationId,
            'token_hash' => hash('sha256', $plain),
            'label' => $label,
            'abilities' => $abilities,
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    public static function findActiveByPlain(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();
    }

    public function touchUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
