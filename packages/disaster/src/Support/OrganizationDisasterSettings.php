<?php

namespace Lumina\Disaster\Support;

use App\Models\OrganizationPlugin;

final class OrganizationDisasterSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function for(int $organizationId): array
    {
        $row = OrganizationPlugin::query()
            ->where('organization_id', $organizationId)
            ->where('plugin_key', 'disaster')
            ->first();

        $settings = is_array($row?->settings) ? $row->settings : [];
        $defaults = [
            'floodplain_layer_id' => null,
            'fault_layer_id' => null,
            'wildfire_layer_id' => null,
            'demographic_layer_id' => null,
            'buildings_layer_id' => null,
            'roads_layer_id' => null,
            'ew_risk_layer_id' => null,
            'default_hazard_level' => config('disaster.risk.default_hazard_level'),
            'risk_weights' => null,
            'avl_max_skew_seconds' => config('disaster.max_skew_seconds'),
            'retention_avl_days' => config('disaster.retention_avl_days'),
            'is_official_authority' => false,
        ];

        return array_merge($defaults, $settings);
    }

    public static function isOfficialAuthority(int $organizationId): bool
    {
        return (bool) (self::for($organizationId)['is_official_authority'] ?? false);
    }
}
