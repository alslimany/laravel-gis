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

class DeleteLayerFromGeoServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $workspace,
        public string $datastore,
        public string $layerName,
        public bool $recurse = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeoServerService $geoserver): void
    {
        try {
            Log::info("Deleting layer {$this->layerName} from GeoServer", [
                'workspace' => $this->workspace,
                'datastore' => $this->datastore,
            ]);

            $geoserver->deleteLayer(
                $this->workspace,
                $this->datastore,
                $this->layerName,
                $this->recurse
            );

            Log::info("Successfully deleted layer {$this->layerName} from GeoServer");
        } catch (GeoServerException $e) {
            Log::error("Failed to delete layer {$this->layerName}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed to delete layer {$this->layerName} after {$this->tries} attempts", [
            'workspace' => $this->workspace,
            'datastore' => $this->datastore,
            'error' => $exception->getMessage(),
        ]);
    }
}
