<?php

namespace App\Jobs;

use App\Exceptions\GeoServerException;
use App\Services\GeoServerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishLayerToGeoServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $workspace,
        public string $datastore,
        public string $tableName,
        public array $options = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeoServerService $geoserver): void
    {
        try {
            Log::info("Publishing layer {$this->tableName} to GeoServer", [
                'workspace' => $this->workspace,
                'datastore' => $this->datastore,
            ]);

            // Ensure workspace exists
            if (! $geoserver->workspaceExists($this->workspace)) {
                $geoserver->createWorkspace($this->workspace);
            }

            // Ensure datastore exists
            if (! $geoserver->datastoreExists($this->workspace, $this->datastore)) {
                $geoserver->createPostGISDatastore($this->workspace, $this->datastore);
            }

            // Publish the layer
            $geoserver->publishLayer(
                $this->workspace,
                $this->datastore,
                $this->tableName,
                $this->options
            );

            Log::info("Successfully published layer {$this->tableName} to GeoServer");
        } catch (GeoServerException $e) {
            Log::error("Failed to publish layer {$this->tableName}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed to publish layer {$this->tableName} after {$this->tries} attempts", [
            'workspace' => $this->workspace,
            'datastore' => $this->datastore,
            'error' => $exception->getMessage(),
        ]);
    }
}
