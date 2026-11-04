<?php

namespace Lumina\Disaster\Console;

use App\Models\Organization;
use Illuminate\Console\Command;
use Lumina\Disaster\Publishing\AssetLayerPublisher;
use Lumina\Disaster\Publishing\OpsLayerPublisher;

class PublishAssetLayersCommand extends Command
{
    protected $signature = 'disaster:publish-asset-layers
        {organization_id : Organization id}
        {--no-sync-geoserver : Skip GeoServer sync}';

    protected $description = 'Publish disaster asset, incident and closure SQL view layers via LayerPublisher';

    public function handle(AssetLayerPublisher $publisher, OpsLayerPublisher $ops): int
    {
        $org = Organization::query()->findOrFail((int) $this->argument('organization_id'));
        $sync = ! $this->option('no-sync-geoserver');
        $results = [$publisher->publish($org, $sync), ...array_values($ops->publish($org, $sync))];

        foreach ($results as $result) {
            $this->info('Layer '.$result['layer']->name.' (published: '.($result['published'] ? 'yes' : 'no').')');
        }

        return self::SUCCESS;
    }
}
