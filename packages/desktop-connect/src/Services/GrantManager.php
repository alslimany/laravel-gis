<?php

namespace Lumina\DesktopConnect\Services;

use App\Exceptions\ReadOnlyLayerException;
use App\Models\Layer;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Models\GrantEvent;

class GrantManager
{
    public function __construct(
        protected FeatureService $features,
        protected DesktopAccess $access,
    ) {}

    /**
     * @param  array{user_id?: int|null, group_id?: int|null, layer_id: int, scope: string, expires_at?: string|null}  $data
     */
    public function grant(User $actor, array $data): Grant
    {
        $organizationId = (int) $actor->organization_id;
        $layer = Layer::query()->findOrFail($data['layer_id']);

        if ((int) $layer->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                'layer_id' => __('desktop.grants.layer_wrong_org'),
            ]);
        }

        $userId = $data['user_id'] ?? null;
        $groupId = $data['group_id'] ?? null;

        if (($userId === null && $groupId === null) || ($userId !== null && $groupId !== null)) {
            throw ValidationException::withMessages([
                'user_id' => __('desktop.grants.subject_xor'),
            ]);
        }

        if ($data['scope'] === Grant::SCOPE_EDIT) {
            try {
                $this->features->assertWritable($layer);
            } catch (ReadOnlyLayerException $e) {
                throw ValidationException::withMessages([
                    'scope' => __('desktop.grants.layer_not_writable'),
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $organizationId, $data, $userId, $groupId) {
            $grant = Grant::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'group_id' => $groupId,
                'layer_id' => $data['layer_id'],
                'scope' => $data['scope'],
                'expires_at' => $data['expires_at'] ?? null,
                'granted_by' => $actor->id,
            ]);

            GrantEvent::query()->create([
                'organization_id' => $organizationId,
                'grant_id' => $grant->id,
                'action' => GrantEvent::ACTION_GRANTED,
                'actor_user_id' => $actor->id,
                'payload' => $grant->toEventPayload(),
                'created_at' => now(),
            ]);

            return $grant;
        });
    }

    public function revoke(User $actor, Grant $grant): void
    {
        if ((int) $grant->organization_id !== (int) $actor->organization_id) {
            abort(403);
        }

        DB::transaction(function () use ($actor, $grant) {
            $payload = $grant->toEventPayload();

            GrantEvent::query()->create([
                'organization_id' => $grant->organization_id,
                'grant_id' => $grant->id,
                'action' => GrantEvent::ACTION_REVOKED,
                'actor_user_id' => $actor->id,
                'payload' => $payload,
                'created_at' => now(),
            ]);

            $grant->delete();
        });
    }
}
