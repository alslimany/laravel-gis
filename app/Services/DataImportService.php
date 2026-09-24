<?php

namespace App\Services;

use App\Models\DataImport;
use App\Models\Layer;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DataImportService
{
    protected string $ogr2ogrPath;

    protected string $ogrinfoPath;

    protected int $timeout;

    public function __construct()
    {
        $this->ogr2ogrPath = Config::get('dataimport.ogr2ogr_path');
        $this->ogrinfoPath = Config::get('dataimport.ogrinfo_path');
        $this->timeout = Config::get('dataimport.timeout');
    }

    /**
     * Get information about a spatial file.
     */
    public function getFileInfo(string $filePath, string $fileType): array
    {
        $command = match ($fileType) {
            'shapefile', 'geojson', 'kml', 'csv' => [$this->ogrinfoPath, '-al', '-so', $filePath],
            default => throw new Exception("Unsupported file type: {$fileType}"),
        };

        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $this->parseOgrInfo($process->getOutput());
    }

    /**
     * Parse ogrinfo output to extract metadata.
     */
    protected function parseOgrInfo(string $output): array
    {
        $info = [
            'geometry_type' => 'Unknown',
            'feature_count' => 0,
            'srid' => null,
            'bbox' => null,
        ];

        // Extract geometry type
        if (preg_match('/Geometry: (.+)/i', $output, $matches)) {
            $info['geometry_type'] = trim($matches[1]);
        }

        // Extract feature count
        if (preg_match('/Feature Count: (\d+)/i', $output, $matches)) {
            $info['feature_count'] = (int) $matches[1];
        }

        // Extract SRID
        if (preg_match('/EPSG[:\s]*(\d+)/i', $output, $matches)) {
            $info['srid'] = (int) $matches[1];
        }

        // Extract bounding box
        if (preg_match('/Extent: \(([^)]+)\)/i', $output, $matches)) {
            $info['bbox'] = $matches[1];
        }

        return $info;
    }

    /**
     * Import spatial file to PostGIS database.
     */
    public function importToPostGIS(
        string $filePath,
        string $tableName,
        string $fileType,
        ?int $targetSrid = null
    ): bool {
        $targetSrid = $targetSrid ?? Config::get('dataimport.default_srid');

        $dbConfig = Config::get('database.connections.pgsql');
        $connectionString = sprintf(
            'PG:host=%s port=%s dbname=%s user=%s password=%s',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password']
        );

        $command = [
            $this->ogr2ogrPath,
            '-f', 'PostgreSQL',
            $connectionString,
            $filePath,
            '-nln', $tableName,
            '-lco', 'GEOMETRY_NAME=geom',
            '-lco', 'FID=id',
            '-lco', 'SPATIAL_INDEX=GIST',
            '-t_srs', "EPSG:{$targetSrid}",
            '-gt', '65536',
            '-overwrite',
            '-progress',
        ];

        // Add specific options for different file types
        if ($fileType === 'csv') {
            $command[] = '-oo';
            $command[] = 'X_POSSIBLE_NAMES=longitude,lon,x';
            $command[] = '-oo';
            $command[] = 'Y_POSSIBLE_NAMES=latitude,lat,y';
            $command[] = '-oo';
            $command[] = 'KEEP_GEOM_COLUMNS=NO';
        }

        Log::info('Running ogr2ogr command', ['command' => implode(' ', $command)]);

        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('ogr2ogr failed', [
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ]);
            throw new ProcessFailedException($process);
        }

        Log::info('Successfully imported data', [
            'table' => $tableName,
            'output' => $process->getOutput(),
        ]);

        return true;
    }

    /**
     * Publish an imported PostGIS table as a feature layer.
     */
    public function publishLayer(
        DataImport $import,
        string $tableName,
        ?string $geometryType,
        int $featureCount
    ): Layer {
        $name = pathinfo($import->file_name, PATHINFO_FILENAME) ?: $tableName;

        return Layer::create([
            'user_id' => $import->user_id,
            'organization_id' => $import->organization_id,
            'name' => $name,
            'description' => 'Feature layer published from '.$import->file_name,
            'table_name' => $tableName,
            'geometry_type' => $geometryType ?: 'Geometry',
            'feature_count' => $featureCount,
            'style_config' => [
                'renderer' => 'simple',
                'fill_color' => '#0f766e',
                'stroke_color' => '#0b1220',
                'stroke_width' => 1,
                'fill_opacity' => 0.35,
            ],
            'metadata' => [
                'source' => $import->file_type,
                'import_id' => $import->id,
            ],
        ]);
    }

    /**
     * Inspect, load, and publish a spatial file. Shapefile zips use GDAL /vsizip.
     */
    public function importDataset(DataImport $import): void
    {
        $disk = config('dataimport.upload_disk');
        $filePath = Storage::disk($disk)->path($import->file_path);
        $source = $this->resolveDatasetPath($filePath);
        $fileType = $import->file_type === 'csv' ? 'csv' : $import->file_type;

        $import->updateProgress(20);
        $fileInfo = $this->getFileInfo($source, $fileType);

        $tableName = $this->generateTableName($import->file_name, $import->organization_id);

        $import->updateProgress(40);
        $this->importToPostGIS($source, $tableName, $fileType);

        $import->updateProgress(80);
        $geometryType = $this->getTableGeometryType($tableName);
        $featureCount = $this->getTableFeatureCount($tableName);
        $layer = $this->publishLayer($import, $tableName, $geometryType, $featureCount);

        $import->update([
            'metadata' => array_merge($import->metadata ?? [], $fileInfo, [
                'layer_id' => $layer->id,
            ]),
        ]);
        $import->markAsCompleted($tableName, $geometryType, $featureCount);
    }

    /**
     * A zipped shapefile is the package Esri accepts. GDAL reads it in place.
     */
    public function resolveDatasetPath(string $filePath): string
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'zip') {
            return '/vsizip/'.$filePath;
        }

        return $filePath;
    }

    /**
     * Get the geometry type from a PostGIS table.
     */
    public function getTableGeometryType(string $tableName): ?string
    {
        $result = DB::selectOne(
            "SELECT ST_GeometryType(geom) as geom_type 
             FROM {$tableName} 
             WHERE geom IS NOT NULL 
             LIMIT 1"
        );

        return $result ? str_replace('ST_', '', $result->geom_type) : null;
    }

    /**
     * Get the feature count from a PostGIS table.
     */
    public function getTableFeatureCount(string $tableName): int
    {
        $result = DB::selectOne("SELECT COUNT(*) as count FROM {$tableName}");

        return (int) $result->count;
    }

    /**
     * Generate a unique table name for imported data.
     */
    public function generateTableName(string $originalFileName, int $organizationId): string
    {
        $prefix = Config::get('dataimport.table_prefix');
        $cleanName = Str::slug(pathinfo($originalFileName, PATHINFO_FILENAME), '_');
        $cleanName = preg_replace('/[^a-z0-9_]/', '', strtolower($cleanName));
        $uniqueId = substr(md5($originalFileName.time()), 0, 8);

        return "{$prefix}org{$organizationId}_{$cleanName}_{$uniqueId}";
    }

    /**
     * Check if ogr2ogr is available.
     */
    public function isOgrAvailable(): bool
    {
        $process = new Process([$this->ogr2ogrPath, '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Delete an imported table from the database.
     */
    public function deleteImportedTable(string $tableName): bool
    {
        try {
            DB::statement("DROP TABLE IF EXISTS {$tableName} CASCADE");

            return true;
        } catch (Exception $e) {
            Log::error("Failed to delete table {$tableName}: {$e->getMessage()}");

            return false;
        }
    }
}
