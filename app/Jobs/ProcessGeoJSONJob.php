<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Services\DataImportService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessGeoJSONJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $dataImportId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DataImportService $importService): void
    {
        $import = DataImport::findOrFail($this->dataImportId);

        try {
            Log::info('Processing GeoJSON import', ['import_id' => $import->id]);

            $import->markAsProcessing();
            $importService->importDataset($import);

            Log::info('Successfully processed spatial import', [
                'import_id' => $import->id,
                'table_name' => $import->fresh()->table_name,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to process GeoJSON import', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            $import->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $import = DataImport::find($this->dataImportId);
        if ($import) {
            $import->markAsFailed($exception->getMessage());
        }

        Log::error('Job failed to process GeoJSON import', [
            'import_id' => $this->dataImportId,
            'error' => $exception->getMessage(),
        ]);
    }
}
