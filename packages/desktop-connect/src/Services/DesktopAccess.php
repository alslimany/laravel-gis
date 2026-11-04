<?php

namespace Lumina\DesktopConnect\Services;

use App\Models\Layer;
use App\Models\User;
use App\Services\FeatureService;
use Lumina\DesktopConnect\Models\Grant;

/**
 * Deny-by-default desktop layer access (ADR-0002 D4).
 *
 * Grants are resolved by layers.id until DG-1.1 adds layers.public_id.
 */
class DesktopAccess
{
    public function __construct(
        protected FeatureService $features,
    ) {}

    /**
     * Whether $user may use the desktop path on $layer at $scope (read|edit).
     * Cross-org always denied. No matching active grant → denied.
     * Edit also requires a writable layer (FeatureService::assertWritable).
     */
    public static function can(User $user, Layer $layer, string $scope): bool
    {
        return app(self::class)->allows($user, $layer, $scope);
    }

    public function allows(User $user, Layer $layer, string $scope): bool
    {
        if (! in_array($scope, Grant::SCOPES, true)) {
            return false;
        }

        if ($user->organization_id === null || $layer->organization_id === null) {
            return false;
        }

        if ((int) $user->organization_id !== (int) $layer->organization_id) {
            return false;
        }

        if ($scope === Grant::SCOPE_EDIT && ! $this->layerIsWritable($layer)) {
            return false;
        }

        return $this->hasActiveGrant($user, $layer, $scope);
    }

    /**
     * Edit implies read: an active edit grant also satisfies read.
     */
    protected function hasActiveGrant(User $user, Layer $layer, string $scope): bool
    {
        $scopes = $scope === Grant::SCOPE_READ
            ? [Grant::SCOPE_READ, Grant::SCOPE_EDIT]
            : [Grant::SCOPE_EDIT];

        $groupIds = $user->groups()
            ->where('groups.organization_id', $user->organization_id)
            ->pluck('groups.id')
            ->all();

        return Grant::query()
            ->where('organization_id', $user->organization_id)
            ->where('layer_id', $layer->id)
            ->whereIn('scope', $scopes)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) use ($user, $groupIds) {
                $q->where('user_id', $user->id);
                if ($groupIds !== []) {
                    $q->orWhereIn('group_id', $groupIds);
                }
            })
            ->exists();
    }

    public function layerIsWritable(Layer $layer): bool
    {
        try {
            $this->features->assertWritable($layer);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
