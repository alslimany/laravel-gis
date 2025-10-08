<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'shapefile' => [$this->ogrinfoPath, '-al', '-so', $filePath],
            'geojson', 'kml' => [$this->ogrinfoPath, '-al', '-so', $filePath],
            default => throw new Exception("Unsupported file type: {$fileType}"),
        };

        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
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

        if (!$process->isSuccessful()) {
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
