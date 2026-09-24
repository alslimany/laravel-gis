<?php

namespace App\Http\Controllers;

use App\Http\Requests\DataImportRequest;
use App\Http\Requests\ImageryImportRequest;
use App\Jobs\ProcessExcelJob;
use App\Jobs\ProcessGeoJSONJob;
use App\Jobs\ProcessImageryJob;
use App\Jobs\ProcessKMLJob;
use App\Jobs\ProcessShapefileJob;
use App\Models\DataImport;
use App\Models\Layer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

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

        return Inertia::render('Imports/Index', [
            'imports' => $imports,
        ]);
    }

    /**
     * Show the upload form.
     */
    public function create()
    {
        $user = Auth::user();
        $imageryLayers = Layer::query()
            ->where('organization_id', $user->organization_id)
            ->where('geometry_type', 'Raster')
            ->orderBy('name')
            ->get(['id', 'name']);

        $kind = request('kind') === 'imagery' || old('_kind') === 'imagery' ? 'imagery' : 'feature';

        return Inertia::render('Imports/Create', [
            'kind' => $kind,
            'imageryLayers' => $imageryLayers,
            'featureLabel' => 'Feature data',
            'imageryLabel' => 'Imagery',
            'imageryHint' => 'A photo without a world file is rejected. Outside this scene’s footprint, the satellite basemap is unchanged.',
            'featureMb' => (int) round(config('dataimport.max_file_size') / 1048576),
            'imageryMb' => (int) round(config('imagery.max_file_size') / 1048576),
        ]);
    }

    /**
     * Handle file upload and dispatch processing job.
     */
    public function store(DataImportRequest $request)
    {
        $user = Auth::user();
        $uploads = $this->collectUploads($request);

        if ($uploads === []) {
            return back()->withErrors(['file' => 'Choose a dataset to add.'])->withInput();
        }

        $disk = config('dataimport.upload_disk');
        $directory = trim(config('dataimport.upload_path'), '/').'/'.Str::uuid();
        $stored = [];

        foreach ($uploads as $upload) {
            $name = $this->safeOriginalName($upload);
            $path = $upload->storeAs($directory, $name, $disk);
            $stored[] = [
                'name' => $name,
                'path' => $path,
                'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'size' => $upload->getSize(),
            ];
        }

        $primary = $this->choosePrimary($stored);
        $sidecars = array_values(array_filter(
            $stored,
            fn (array $file) => $file['path'] !== $primary['path']
        ));

        $import = DataImport::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'file_name' => $primary['name'],
            'file_path' => $primary['path'],
            'file_type' => $this->determineFileType($primary['extension']),
            'file_size' => array_sum(array_column($stored, 'size')),
            'status' => 'pending',
            'metadata' => [
                'additional_files' => array_column($sidecars, 'path'),
                'package' => array_column($stored, 'name'),
            ],
        ]);

        $this->dispatchProcessingJob($import);

        return redirect()
            ->route('imports.show', $import)
            ->with('success', 'Dataset accepted. It is publishing as a feature layer in the background.');
    }

    /**
     * Accept a georeferenced image and publish it as an imagery layer.
     */
    public function storeImagery(ImageryImportRequest $request)
    {
        $user = Auth::user();

        if ($user->organization_id === null) {
            return back()->withErrors(['files' => 'Join an organization before publishing imagery.'])->withInput();
        }

        $layer = null;
        if ($request->filled('layer_id')) {
            $layer = Layer::query()->findOrFail($request->integer('layer_id'));
            $this->authorize('update', $layer);

            if ($layer->geometry_type !== 'Raster' || $layer->organization_id !== $user->organization_id) {
                return back()->withErrors(['layer_id' => 'Choose an imagery layer from this organization.'])->withInput();
            }
        }

        $disk = config('dataimport.upload_disk');
        $directory = trim(config('dataimport.upload_path'), '/').'/'.Str::uuid();
        $stored = [];

        foreach ($request->file('files') as $upload) {
            $name = $this->safeOriginalName($upload);
            $path = $upload->storeAs($directory, $name, $disk);
            $stored[] = [
                'name' => $name,
                'path' => $path,
                'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'size' => $upload->getSize(),
            ];
        }

        $primary = $this->chooseImageryPrimary($stored);
        $sidecars = array_values(array_filter(
            $stored,
            fn (array $file) => $file['path'] !== $primary['path']
        ));

        $import = DataImport::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'file_name' => $primary['name'],
            'file_path' => $primary['path'],
            'file_type' => 'imagery',
            'file_size' => array_sum(array_column($stored, 'size')),
            'status' => 'pending',
            'metadata' => [
                'additional_files' => array_column($sidecars, 'path'),
                'package' => array_column($stored, 'name'),
                'acquired_at' => $request->date('acquired_at')->toDateString(),
                'name' => $request->input('name') ?: pathinfo($primary['name'], PATHINFO_FILENAME),
                'target_layer_id' => $layer?->id,
                'kind' => 'imagery',
            ],
        ]);

        ProcessImageryJob::dispatch($import->id)->afterResponse();

        return redirect()
            ->route('imports.show', $import)
            ->with('success', 'Imagery accepted. It is being prepared and published above the satellite basemap. Where it overlaps an older scene, the newer capture date stays on top.');
    }

    /**
     * Show import details and status.
     */
    public function show(DataImport $import)
    {
        $this->authorize('view', $import);

        return Inertia::render('Imports/Show', [
            'import' => $import,
        ]);
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

        if (! empty($import->metadata['additional_files'])) {
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
            'layer_id' => $import->metadata['layer_id'] ?? null,
        ]);
    }

    /**
     * Determine file type based on extension.
     */
    protected function determineFileType(string $extension): string
    {
        return match ($extension) {
            'shp', 'zip' => 'shapefile',
            'geojson', 'json' => 'geojson',
            'kml', 'kmz' => 'kml',
            'csv' => 'csv',
            'xlsx', 'xls' => 'xlsx',
            default => 'unknown',
        };
    }

    /**
     * Dispatch the appropriate processing job based on file type.
     */
    protected function dispatchProcessingJob(DataImport $import): void
    {
        match ($import->file_type) {
            'shapefile' => ProcessShapefileJob::dispatch($import->id)->afterResponse(),
            'geojson', 'csv' => ProcessGeoJSONJob::dispatch($import->id)->afterResponse(),
            'kml' => ProcessKMLJob::dispatch($import->id)->afterResponse(),
            'xlsx' => ProcessExcelJob::dispatch($import->id)->afterResponse(),
            default => null,
        };
    }

    /**
     * @return list<UploadedFile>
     */
    protected function collectUploads(DataImportRequest $request): array
    {
        $uploads = [];

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                if ($file instanceof UploadedFile) {
                    $uploads[] = $file;
                }
            }
        }

        if ($request->hasFile('file')) {
            $uploads[] = $request->file('file');
        }

        if ($request->hasFile('additional_files')) {
            foreach ($request->file('additional_files') as $file) {
                if ($file instanceof UploadedFile) {
                    $uploads[] = $file;
                }
            }
        }

        return $uploads;
    }

    protected function safeOriginalName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'dataset';

        return $name;
    }

    /**
     * Shapefile parts stay beside the .shp. A zip is the Esri package.
     * A lone KML, GeoJSON, CSV, or Excel file is the dataset itself.
     *
     * @param  list<array{name: string, path: string, extension: string, size: int}>  $stored
     * @return array{name: string, path: string, extension: string, size: int}
     */
    protected function choosePrimary(array $stored): array
    {
        foreach (['shp', 'zip', 'kml', 'kmz', 'geojson', 'json', 'csv', 'xlsx', 'xls'] as $extension) {
            foreach ($stored as $file) {
                if ($file['extension'] === $extension) {
                    return $file;
                }
            }
        }

        return $stored[0];
    }

    /**
     * @param  list<array{name: string, path: string, extension: string, size: int}>  $stored
     * @return array{name: string, path: string, extension: string, size: int}
     */
    protected function chooseImageryPrimary(array $stored): array
    {
        foreach (['tif', 'tiff', 'zip', 'jpg', 'jpeg', 'png'] as $extension) {
            foreach ($stored as $file) {
                if ($file['extension'] === $extension) {
                    return $file;
                }
            }
        }

        return $stored[0];
    }
}
