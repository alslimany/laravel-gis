<?php

namespace Lumina\Disaster\Console;

use App\Models\OrganizationPlugin;
use Illuminate\Console\Command;
use Lumina\Disaster\Services\WarningAcknowledgementService;

class EscalateWarningsCommand extends Command
{
    protected $signature = 'disaster:escalate-warnings {--organization= : Limit to one organization id}';

    protected $description = 'Escalate unacknowledged warning activations past configured timeouts';

    public function handle(WarningAcknowledgementService $service): int
    {
        $organizationId = $this->option('organization');

        $query = OrganizationPlugin::query()
            ->where('plugin_key', 'disaster')
            ->where('enabled', true);

        if ($organizationId !== null && $organizationId !== '') {
            $query->where('organization_id', (int) $organizationId);
        }

        $total = 0;
        foreach ($query->pluck('organization_id') as $orgId) {
            $created = $service->escalateDue((int) $orgId);
            $total += $created;
            $this->line("Organization {$orgId}: {$created} escalation recipient(s)");
        }

        $this->info("Created {$total} escalation recipient(s).");

        return self::SUCCESS;
    }
}
