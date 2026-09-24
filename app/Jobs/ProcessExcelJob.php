<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Services\DataImportService;
use App\Services\ExcelImportService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessExcelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 10;

    public function __construct(
        public int $dataImportId
    ) {}

    public function handle(ExcelImportService $excelImport, DataImportService $importService): void
    {
        $import = DataImport::findOrFail($this->dataImportId);

        try {
            Log::info('Processing Excel import', ['import_id' => $import->id]);

            $import->markAsProcessing();

            $disk = config('dataimport.upload_disk');
            $filePath = Storage::disk($disk)->path($import->file_path);

            $import->updateProgress(20);

            $result = $excelImport->import($filePath, $import, $importService);

            $import->updateProgress(90);
            $import->update([
                'metadata' => array_merge($import->metadata ?? [], [
                    'layer_id' => $result['layer']->id,
                    'geometry_mode' => $result['layer']->metadata['geometry_mode'] ?? null,
                ]),
            ]);

            $import->markAsCompleted(
                $result['table_name'],
                $result['geometry_type'],
                $result['feature_count']
            );

            Log::info('Successfully processed Excel import', [
                'import_id' => $import->id,
                'table_name' => $result['table_name'],
                'layer_id' => $result['layer']->id,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to process Excel import', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            $import->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $import = DataImport::find($this->dataImportId);
        if ($import) {
            $import->markAsFailed($exception->getMessage());
        }

        Log::error('Job failed to process Excel import', [
            'import_id' => $this->dataImportId,
            'error' => $exception->getMessage(),
        ]);
    }
}
