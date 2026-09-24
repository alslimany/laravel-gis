<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Services\ImageryImportService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessImageryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $dataImportId
    ) {}

    public function handle(ImageryImportService $imagery): void
    {
        $import = DataImport::findOrFail($this->dataImportId);

        try {
            $import->markAsProcessing();
            $layer = $imagery->import($import);
            $import->markAsCompleted($layer->table_name, 'Raster', (int) $layer->feature_count);

            Log::info('Published imagery layer', [
                'import_id' => $import->id,
                'layer_id' => $layer->id,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish imagery', [
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
        if ($import && $import->status !== 'failed') {
            $import->markAsFailed($exception->getMessage());
        }
    }
}
