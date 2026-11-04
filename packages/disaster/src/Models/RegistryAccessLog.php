<?php

namespace Lumina\Disaster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Lumina\Disaster\Models\Concerns\BelongsToOrganization;
use Lumina\Disaster\Support\Enums\RegistryAccessAction;

/**
 * Append-only audit trail for personal-registry reads, exports, and updates.
 */
class RegistryAccessLog extends Model
{
    use BelongsToOrganization;

    protected $table = 'dm_registry_access_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'action' => RegistryAccessAction::class,
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('dm_registry_access_log is append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('dm_registry_access_log is append-only and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        int $organizationId,
        int $userId,
        string $subjectType,
        int $subjectId,
        RegistryAccessAction $action,
        ?string $ip = null,
    ): self {
        return static::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
