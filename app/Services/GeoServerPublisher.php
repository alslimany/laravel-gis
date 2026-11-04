<?php

namespace App\Services;

use App\Jobs\PublishLayerToGeoServer;
use App\Models\Layer;
use App\Models\Organization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes layers to GeoServer synchronously, collecting per-layer failures
 * instead of aborting the whole batch.
 */
class GeoServerPublisher
{
    /**
     * @param  list<Layer>  $layers
     * @return array{published: int, failed: list<string>}
     */
    public function publish(Organization $organization, array $layers): array
    {
        $workspace = $organization->getGeoServerWorkspace();
        $datastore = (string) Config::get('geoserver.datastore');
        $published = 0;
        $failed = [];

        foreach ($layers as $layer) {
            if ($layer->isPublished()) {
                continue;
            }

            if (blank($layer->table_name)) {
                $failed[] = $layer->name;

                continue;
            }

            try {
                $publishName = $layer->geoServerPublishName();
                PublishLayerToGeoServer::dispatchSync($workspace, $datastore, $layer->table_name, [
                    'name' => $publishName,
                    'title' => $layer->name,
                    'abstract' => $layer->description ?? "Layer: {$layer->name}",
                    'srs' => 'EPSG:4326',
                ]);

                $layer->markAsPublished($publishName, $workspace);
                $published++;
            } catch (Throwable $e) {
                Log::warning('Failed to publish pack layer to GeoServer', [
                    'layer' => $layer->name,
                    'table' => $layer->table_name,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = $layer->name;
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }
}
