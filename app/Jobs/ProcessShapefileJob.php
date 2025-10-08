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
use Illuminate\Support\Facades\Storage;

class ProcessShapefileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

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
            Log::info("Processing Shapefile import", ['import_id' => $import->id]);

            $import->markAsProcessing();

            // Get the full file path
            $disk = config('dataimport.upload_disk');
            $filePath = Storage::disk($disk)->path($import->file_path);

            // Generate table name
            $tableName = $importService->generateTableName(
                $import->file_name,
                $import->organization_id
            );

            // Get file information
            $import->updateProgress(20);
            $fileInfo = $importService->getFileInfo($filePath, 'shapefile');

            // Import to PostGIS
            $import->updateProgress(40);
            $importService->importToPostGIS($filePath, $tableName, 'shapefile');

            // Get actual geometry type and feature count from imported table
            $import->updateProgress(80);
            $geometryType = $importService->getTableGeometryType($tableName);
            $featureCount = $importService->getTableFeatureCount($tableName);

            // Update import record
            $import->update(['metadata' => array_merge($import->metadata ?? [], $fileInfo)]);
            $import->markAsCompleted($tableName, $geometryType, $featureCount);

            Log::info("Successfully processed Shapefile import", [
                'import_id' => $import->id,
                'table_name' => $tableName,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to process Shapefile import", [
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

        Log::error("Job failed to process Shapefile import", [
            'import_id' => $this->dataImportId,
            'error' => $exception->getMessage(),
        ]);
    }
}
