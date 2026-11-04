<?php

namespace Lumina\DesktopConnect\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Lumina\DesktopConnect\Models\ApiKey;
use Lumina\DesktopConnect\Models\Grant;

/**
 * Mint / revoke desktop Sanctum keys (DG-1.6). Plain text is a UUID (AuthKey-compatible).
 */
class DesktopKeyManager
{
    public function __construct(
        protected DesktopAccess $access,
    ) {}

    /**
     * Concrete dc:layer:{publicId}:read|edit abilities for the user's active grants.
     *
     * @return list<string>
     */
    public function abilitiesFor(User $user): array
    {
        if ($user->organization_id === null) {
            return [];
        }

        $groupIds = $user->groups()
            ->where('groups.organization_id', $user->organization_id)
            ->pluck('groups.id')
            ->all();

        $grants = Grant::query()
            ->where('organization_id', $user->organization_id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) use ($user, $groupIds) {
                $q->where('user_id', $user->id);
                if ($groupIds !== []) {
                    $q->orWhereIn('group_id', $groupIds);
                }
            })
            ->with(['layer:id,public_id,organization_id,metadata'])
            ->get();

        $abilities = [];

        foreach ($grants as $grant) {
            $layer = $grant->layer;
            if ($layer === null || blank($layer->public_id)) {
                continue;
            }

            if (! $this->access->allows($user, $layer, $grant->scope)) {
                continue;
            }

            $publicId = (string) $layer->public_id;

            if ($grant->scope === Grant::SCOPE_EDIT) {
                $abilities[] = "dc:layer:{$publicId}:edit";
                $abilities[] = "dc:layer:{$publicId}:read";
            } else {
                $abilities[] = "dc:layer:{$publicId}:read";
            }
        }

        $abilities = array_values(array_unique($abilities));
        sort($abilities);

        return $abilities;
    }

    /**
     * @return array{key: ApiKey, plain_text: string, abilities: list<string>}
     */
    public function mint(User $user, string $label, ?\DateTimeInterface $expiresAt = null): array
    {
        if ($user->organization_id === null) {
            throw ValidationException::withMessages([
                'label' => __('desktop.keys.no_organization'),
            ]);
        }

        $abilities = $this->abilitiesFor($user);
        if ($abilities === []) {
            throw ValidationException::withMessages([
                'label' => __('desktop.keys.no_grants'),
            ]);
        }

        if ($expiresAt !== null && $expiresAt <= now()) {
            throw ValidationException::withMessages([
                'expires_at' => __('desktop.keys.expires_past'),
            ]);
        }

        return DB::transaction(function () use ($user, $label, $expiresAt, $abilities) {
            $plainText = (string) Str::uuid();

            /** @var PersonalAccessToken $token */
            $token = $user->tokens()->create([
                'name' => 'desktop:'.$label,
                'token' => hash('sha256', $plainText),
                'abilities' => $abilities,
                'expires_at' => $expiresAt,
            ]);

            $key = ApiKey::query()->create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'personal_access_token_id' => $token->id,
                'label' => $label,
                'expires_at' => $expiresAt,
            ]);

            return [
                'key' => $key,
                'plain_text' => $plainText,
                'abilities' => $abilities,
            ];
        });
    }

    public function revoke(User $actor, ApiKey $key): void
    {
        if ((int) $key->organization_id !== (int) $actor->organization_id) {
            abort(403);
        }

        if ((int) $key->user_id !== (int) $actor->id && ! $actor->hasRole('admin')) {
            abort(403);
        }

        DB::transaction(function () use ($key) {
            $tokenId = $key->personal_access_token_id;
            $key->delete();
            PersonalAccessToken::query()->whereKey($tokenId)->delete();
        });
    }
}
