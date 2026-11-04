<?php

namespace Lumina\Disaster\Console;

use App\Models\OrganizationPlugin;
use Illuminate\Console\Command;
use Lumina\Disaster\Services\CapDisseminationService;

class RetryDisseminationCommand extends Command
{
    protected $signature = 'disaster:retry-dissemination {--organization= : Limit to one organization id}';

    protected $description = 'Dispatch due CAP/SMS dissemination retries with backoff';

    public function handle(CapDisseminationService $service): int
    {
        $organizationId = $this->option('organization');

        if ($organizationId !== null && $organizationId !== '') {
            $dispatched = $service->retryDue((int) $organizationId);
            $this->info('Dispatched '.count($dispatched).' dissemination retry job(s).');

            return self::SUCCESS;
        }

        $total = 0;
        $orgIds = OrganizationPlugin::query()
            ->where('plugin_key', 'disaster')
            ->where('enabled', true)
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            $dispatched = $service->retryDue((int) $orgId);
            $count = count($dispatched);
            $total += $count;
            if ($count > 0) {
                $this->line("Organization {$orgId}: {$count} retry job(s)");
            }
        }

        $this->info("Dispatched {$total} dissemination retry job(s).");

        return self::SUCCESS;
    }
}
