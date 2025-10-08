<?php

namespace App\Console\Commands;

use App\Jobs\PublishLayerToGeoServer;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class PublishOrganizationLayers extends Command
{
    protected $signature = 'geoserver:publish-org-layers {organization_id}';

    protected $description = 'Publish all layers for an organization to GeoServer';

    public function handle(): int
    {
        $organizationId = $this->argument('organization_id');
        $organization = Organization::find($organizationId);

        if (! $organization) {
            $this->error("Organization with ID {$organizationId} not found");

            return self::FAILURE;
        }

        $this->info("Publishing layers for organization: {$organization->name}");

        $workspace = 'org_'.$organizationId;
        $datastore = Config::get('geoserver.datastore');

        // Dispatch job to publish projects layer
        PublishLayerToGeoServer::dispatch(
            $workspace,
            $datastore,
            'projects',
            [
                'title' => "{$organization->name} - Projects",
                'abstract' => "Projects for organization {$organization->name}",
                'srs' => 'EPSG:4326',
            ]
        );

        $this->info('✓ Projects layer publishing job dispatched');

        // Dispatch job to publish users layer if needed
        PublishLayerToGeoServer::dispatch(
            $workspace,
            $datastore,
            'users',
            [
                'title' => "{$organization->name} - Users",
                'abstract' => "User locations for organization {$organization->name}",
                'srs' => 'EPSG:4326',
            ]
        );

        $this->info('✓ Users layer publishing job dispatched');

        $this->newLine();
        $this->info('All layer publishing jobs have been dispatched to the queue.');
        $this->info('Monitor the queue worker to see progress: php artisan queue:work');

        return self::SUCCESS;
    }
}
