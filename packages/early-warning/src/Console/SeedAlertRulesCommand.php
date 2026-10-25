<?php

namespace Lumina\EarlyWarning\Console;

use App\Models\Organization;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Lumina\EarlyWarning\Alerts\DefaultAlertRules;

class SeedAlertRulesCommand extends Command
{
    protected $signature = 'early-warning:seed-alert-rules
        {--organization= : Organization id (default: all with plugin enabled)}';

    protected $description = 'Seed Tripoli-calibrated default AlertRule rows (idempotent by name)';

    public function handle(DefaultAlertRules $seeder, PluginManager $plugins): int
    {
        $orgId = $this->option('organization');
        if ($orgId !== null && $orgId !== '') {
            $org = Organization::query()->find((int) $orgId);
            if (! $org) {
                $this->error("Organization {$orgId} not found.");

                return self::FAILURE;
            }
            $created = $seeder->seed($org);
            $this->info("Seeded {$created} new rule(s) for organization {$org->id}.");

            return self::SUCCESS;
        }

        $ids = $plugins->enabledOrganizations('early-warning');
        if ($ids->isEmpty()) {
            $this->warn('No organizations have early-warning enabled.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($ids as $id) {
            $org = Organization::query()->find((int) $id);
            if ($org) {
                $total += $seeder->seed($org);
            }
        }

        $this->info("Seeded {$total} new rule(s) across {$ids->count()} organization(s).");

        return self::SUCCESS;
    }
}
