<?php

namespace Lumina\EarlyWarning\Alerts;

use App\Models\Organization;
use Lumina\EarlyWarning\Models\AlertRule;

/** Seeds org AlertRule rows from config defaults (idempotent by name). */
class DefaultAlertRules
{
    public function seed(Organization $organization): int
    {
        $defaults = config('early-warning.alerts.defaults', []);
        $created = 0;
        $now = now();

        foreach ($defaults as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }

            $rule = AlertRule::query()->firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'name' => (string) $row['name'],
                ],
                [
                    'basin_code' => $row['basin_code'] ?? null,
                    'metric' => (string) ($row['metric'] ?? 'precip_6h_mm'),
                    'operator' => (string) ($row['operator'] ?? '>='),
                    'threshold' => (float) ($row['threshold'] ?? 0),
                    'risk_level_min' => $row['risk_level_min'] ?? null,
                    'channels' => $row['channels'] ?? ['log'],
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            if ($rule->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
