<?php

namespace App\Console\Commands;

use App\Services\GeoServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestGeoServerConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geoserver:test {--workspace=test_workspace} {--table=users}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test GeoServer connection and publish a sample layer';

    /**
     * Execute the console command.
     */
    public function handle(GeoServerService $geoserver): int
    {
        $this->info('Testing GeoServer connection...');
        $this->info('GeoServer URL: '.Config::get('geoserver.url'));

        $workspace = $this->option('workspace');
        $datastore = Config::get('geoserver.datastore');
        $tableName = $this->option('table');

        try {
            // Test 1: Create workspace
            $this->info("\n1. Testing workspace creation...");
            if ($geoserver->workspaceExists($workspace)) {
                $this->info("✓ Workspace '{$workspace}' already exists");
            } else {
                $geoserver->createWorkspace($workspace);
                $this->info("✓ Workspace '{$workspace}' created successfully");
            }

            // Test 2: Create PostGIS datastore
            $this->info("\n2. Testing PostGIS datastore creation...");
            if ($geoserver->datastoreExists($workspace, $datastore)) {
                $this->info("✓ Datastore '{$datastore}' already exists");
            } else {
                $geoserver->createPostGISDatastore($workspace, $datastore);
                $this->info("✓ Datastore '{$datastore}' created successfully");
            }

            // Test 3: Check if layer exists
            $this->info("\n3. Checking if layer '{$tableName}' exists...");
            if ($geoserver->layerExists($workspace, $tableName)) {
                $this->info("✓ Layer '{$tableName}' already exists");

                if ($this->confirm('Do you want to delete it and recreate?')) {
                    $geoserver->deleteLayer($workspace, $datastore, $tableName);
                    $this->info("✓ Layer '{$tableName}' deleted");
                } else {
                    $this->info('Skipping layer creation');

                    return self::SUCCESS;
                }
            }

            // Test 4: Publish layer
            $this->info("\n4. Publishing layer '{$tableName}'...");
            $geoserver->publishLayer($workspace, $datastore, $tableName, [
                'title' => ucfirst($tableName).' Layer',
                'abstract' => 'Test layer published from '.$tableName.' table',
                'srs' => 'EPSG:4326',
            ]);
            $this->info("✓ Layer '{$tableName}' published successfully");

            // Test 5: Create and apply a default style
            $this->info("\n5. Creating and applying default style...");
            $styleName = $tableName.'_style';
            $sldContent = $geoserver->getDefaultPointStyle($styleName, [
                'color' => '#0000FF',
                'size' => 8,
            ]);

            $geoserver->createOrUpdateStyle($workspace, $styleName, $sldContent);
            $this->info("✓ Style '{$styleName}' created");

            $geoserver->applyStyleToLayer($workspace, $tableName, $styleName);
            $this->info("✓ Style '{$styleName}' applied to layer");

            $this->newLine();
            $this->info('✓ All tests passed successfully!');
            $this->newLine();
            $this->info('You can now view the layer in GeoServer:');
            $this->info(Config::get('geoserver.url').'/web/?wicket:bookmarkablePage=:org.geoserver.web.data.layer.LayerPage');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\n✗ Test failed: ".$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
