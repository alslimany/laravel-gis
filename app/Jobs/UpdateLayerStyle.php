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

class UpdateLayerStyle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $workspace,
        public string $layerName,
        public string $styleName,
        public ?string $sldContent = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeoServerService $geoserver): void
    {
        try {
            Log::info("Updating style for layer {$this->layerName} in GeoServer", [
                'workspace' => $this->workspace,
                'styleName' => $this->styleName,
            ]);

            // Create or update the style if SLD content is provided
            if ($this->sldContent) {
                $geoserver->createOrUpdateStyle(
                    $this->workspace,
                    $this->styleName,
                    $this->sldContent
                );
            }

            // Apply the style to the layer
            $geoserver->applyStyleToLayer(
                $this->workspace,
                $this->layerName,
                $this->styleName
            );

            Log::info("Successfully updated style for layer {$this->layerName}");
        } catch (GeoServerException $e) {
            Log::error("Failed to update style for layer {$this->layerName}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed to update style for layer {$this->layerName} after {$this->tries} attempts", [
            'workspace' => $this->workspace,
            'styleName' => $this->styleName,
            'error' => $exception->getMessage(),
        ]);
    }
}
