<?php

namespace Lumina\EarlyWarning\Console;

use App\Models\Organization;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Lumina\EarlyWarning\Publishing\RiskLayerPublisher;

class PublishRiskLayerCommand extends Command
{
    protected $signature = 'early-warning:publish-risk-layer
        {--organization= : Organization id (default: all enabled)}
        {--no-geoserver : Only upsert the layers row; skip GeoServer}';

    protected $description = 'Publish basin risk as an org Layer + GeoServer SQL View (#61)';

    public function handle(RiskLayerPublisher $publisher, PluginManager $plugins): int
    {
        if (! $plugins->isInstalled('early-warning')) {
            $this->error('early-warning is not installed.');

            return self::FAILURE;
        }

        $orgOption = $this->option('organization');
        $orgIds = $orgOption !== null
            ? collect([(int) $orgOption])
            : $plugins->enabledOrganizations('early-warning');

        if ($orgIds->isEmpty()) {
            $this->warn('No enabled organizations.');

            return self::SUCCESS;
        }

        $syncGs = ! $this->option('no-geoserver');
        foreach ($orgIds as $id) {
            $org = Organization::query()->find($id);
            if (! $org) {
                $this->error("Organization {$id} not found.");

                continue;
            }
            if (! $plugins->isEnabledFor('early-warning', $org)) {
                $this->warn("early-warning not enabled for organization {$id}; skipping.");

                continue;
            }

            $result = $publisher->publish($org, $syncGs);
            $this->info(sprintf(
                'Org %d: layer #%d%s',
                $id,
                $result['layer']->id,
                $result['published'] ? ' published to GeoServer' : ($syncGs ? ' (GeoServer skipped/failed)' : ' (no GeoServer)'),
            ));
        }

        return self::SUCCESS;
    }
}
