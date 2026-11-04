<?php

namespace Lumina\Disaster\Console;

use App\Models\OrganizationPlugin;
use Illuminate\Console\Command;
use Lumina\Disaster\Jobs\GeofenceCheckJob;

class GeofenceCheckCommand extends Command
{
    protected $signature = 'disaster:geofence-check {--organization= : Limit to one organization id}';

    protected $description = 'Log geofence dissemination events when AVL units are near open incidents';

    public function handle(): int
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
            $written = (new GeofenceCheckJob((int) $orgId))->handle();
            $total += $written;
            $this->line("Organization {$orgId}: {$written} geofence log(s)");
        }

        $this->info("Wrote {$total} geofence dissemination log(s).");

        return self::SUCCESS;
    }
}
