<?php

namespace App\Http\Controllers;

use App\Http\Requests\DataImportRequest;
use App\Jobs\ProcessGeoJSONJob;
use App\Jobs\ProcessKMLJob;
use App\Jobs\ProcessShapefileJob;
use App\Models\DataImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DataImportController extends Controller
{
    /**
     * Display the data import upload form.
     */
    public function index()
    {
        $imports = DataImport::where('user_id', Auth::id())
            ->with(['user', 'organization'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('imports.index', compact('imports'));
    }

    /**
     * Show the upload form.
     */
    public function create()
    {
        return view('imports.create');
    }

    /**
     * Handle file upload and dispatch processing job.
     */
    public function store(DataImportRequest $request)
    {
        $user = Auth::user();
        $file = $request->file('file');

        // Determine file type
        $extension = strtolower($file->getClientOriginalExtension());
        $fileType = $this->determineFileType($extension);

        // Store the main file
        $disk = config('dataimport.upload_disk');
        $uploadPath = config('dataimport.upload_path');
        $fileName = $file->getClientOriginalName();
        $filePath = $file->store($uploadPath, $disk);

        // Store additional files for Shapefile (shx, dbf, prj, etc.)
        $additionalFiles = [];
        if ($fileType === 'shapefile' && $request->hasFile('additional_files')) {
            foreach ($request->file('additional_files') as $additionalFile) {
                $additionalPath = $additionalFile->store($uploadPath, $disk);
                $additionalFiles[] = $additionalPath;
            }
        }

        // Create import record
        $import = DataImport::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size' => $file->getSize(),
            'status' => 'pending',
            'metadata' => [
                'additional_files' => $additionalFiles,
            ],
        ]);

        // Dispatch appropriate processing job
        $this->dispatchProcessingJob($import);

        return redirect()
            ->route('imports.show', $import)
            ->with('success', 'File uploaded successfully. Processing has started.');
    }

    /**
     * Show import details and status.
     */
    public function show(DataImport $import)
    {
        $this->authorize('view', $import);

        return view('imports.show', compact('import'));
    }

    /**
     * Delete an import and its associated data.
     */
    public function destroy(DataImport $import)
    {
        $this->authorize('delete', $import);

        // Delete uploaded files
        $disk = config('dataimport.upload_disk');
        Storage::disk($disk)->delete($import->file_path);

        if (!empty($import->metadata['additional_files'])) {
            foreach ($import->metadata['additional_files'] as $filePath) {
                Storage::disk($disk)->delete($filePath);
            }
        }

        // Delete the import record (table deletion is optional)
        $import->delete();

        return redirect()
            ->route('imports.index')
            ->with('success', 'Import deleted successfully.');
    }

    /**
     * Get import status via AJAX.
     */
    public function status(DataImport $import)
    {
        $this->authorize('view', $import);

        return response()->json([
            'status' => $import->status,
            'progress' => $import->progress,
            'error_message' => $import->error_message,
            'table_name' => $import->table_name,
            'geometry_type' => $import->geometry_type,
            'feature_count' => $import->feature_count,
        ]);
    }

    /**
     * Determine file type based on extension.
     */
    protected function determineFileType(string $extension): string
    {
        return match ($extension) {
            'shp' => 'shapefile',
            'geojson', 'json' => 'geojson',
            'kml', 'kmz' => 'kml',
            'csv' => 'csv',
            default => 'unknown',
        };
    }

    /**
     * Dispatch the appropriate processing job based on file type.
     */
    protected function dispatchProcessingJob(DataImport $import): void
    {
        match ($import->file_type) {
            'shapefile' => ProcessShapefileJob::dispatch($import->id),
            'geojson' => ProcessGeoJSONJob::dispatch($import->id),
            'kml' => ProcessKMLJob::dispatch($import->id),
            default => null,
        };
    }
}
