<?php

namespace App\Services;

use App\Models\ContentAccess;
use App\Models\DashboardBoard;
use App\Models\Layer;
use App\Models\Map;
use App\Models\User;

class ContentAccessService
{
    /**
     * Determine whether the user may view the given content.
     *
     * Visibility rules (from content_access, defaulting to organization when no row):
     * - public: anyone authenticated (caller still decides anonymous/share-token access)
     * - organization: members of the content's organization
     * - group: members of the linked group
     * - private: content owner or organization admin
     */
    public function canView(User $user, string $type, int $id, ?int $organizationId): bool
    {
        $access = ContentAccess::query()
            ->where('content_type', $type)
            ->where('content_id', $id)
            ->first();

        if (! $access) {
            return $organizationId !== null
                && $user->organization_id === $organizationId;
        }

        return match ($access->visibility) {
            'public' => true,
            'organization' => $user->organization_id === $access->organization_id,
            'group' => $this->userBelongsToGroup($user, $access->group_id),
            'private' => $this->canViewPrivate($user, $type, $id, $access),
            default => false,
        };
    }

    /**
     * Whether the user may view the given content.
     * Convenience for filtering collections after an organization-scoped query.
     */
    public function userCanView(User $user, string $type, $model): bool
    {
        return $this->canView($user, $type, (int) $model->id, $model->organization_id);
    }

    /**
     * Filter a collection of layers/maps/dashboards to those the user may view.
     *
     * @param  \Illuminate\Support\Collection|iterable  $items
     * @return \Illuminate\Support\Collection
     */
    public function filterVisible(User $user, string $type, $items)
    {
        return collect($items)->filter(
            fn ($item) => $this->canView($user, $type, (int) $item->id, $item->organization_id)
        )->values();
    }

    /**
     * Upsert visibility for morphable content (layer / map / dashboard).
     */
    public function setVisibility(
        string $type,
        int $id,
        string $visibility,
        int $organizationId,
        ?int $groupId = null
    ): ContentAccess {
        return ContentAccess::query()->updateOrCreate(
            [
                'content_type' => $type,
                'content_id' => $id,
            ],
            [
                'visibility' => $visibility,
                'organization_id' => $organizationId,
                'group_id' => $visibility === 'group' ? $groupId : null,
            ]
        );
    }

    protected function userBelongsToGroup(User $user, ?int $groupId): bool
    {
        if (! $groupId) {
            return false;
        }

        return $user->groups()->where('groups.id', $groupId)->exists();
    }

    protected function canViewPrivate(User $user, string $type, int $id, ContentAccess $access): bool
    {
        if ($user->organization_id !== $access->organization_id) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        $ownerId = match ($type) {
            'layer' => Layer::query()->whereKey($id)->value('user_id'),
            'map' => Map::query()->whereKey($id)->value('user_id'),
            'dashboard' => DashboardBoard::query()->whereKey($id)->value('user_id'),
            default => null,
        };

        return $ownerId !== null && (int) $ownerId === (int) $user->id;
    }
}
