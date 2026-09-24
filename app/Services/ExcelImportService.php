<?php

namespace App\Services;

use App\Models\DataImport;
use App\Models\Layer;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportService
{
    /**
     * Import an Excel file into PostGIS and create a Layer record.
     *
     * @return array{table_name: string, geometry_type: string, feature_count: int, layer: Layer}
     */
    public function import(string $filePath, DataImport $import, ?DataImportService $dataImportService = null): array
    {
        $dataImportService ??= app(DataImportService::class);

        if (! is_readable($filePath)) {
            throw new Exception("Excel file not readable: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            throw new Exception('Excel file must contain a header row and at least one data row.');
        }

        $headerRow = array_shift($rows);
        $headers = $this->normalizeHeaders($headerRow);
        $geoColumns = $this->detectGeometryColumns($headers);

        if ($geoColumns['mode'] === null) {
            throw new Exception('Excel file must include lat/lon columns or a WKT geometry column.');
        }

        $tableName = $dataImportService->generateTableName(
            $import->file_name,
            $import->organization_id
        );

        $geoCols = array_filter([
            $geoColumns['lat'],
            $geoColumns['lon'],
            $geoColumns['wkt'],
        ]);

        $attributeHeaders = array_filter(
            $headers,
            fn ($name, $col) => ! in_array($col, $geoCols, true),
            ARRAY_FILTER_USE_BOTH
        );

        $this->createTable($tableName, $attributeHeaders);

        $srid = (int) Config::get('dataimport.default_srid', 4326);
        $inserted = 0;
        $chunkSize = (int) Config::get('dataimport.chunk_size', 1000);
        $batch = [];

        foreach ($rows as $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $attributes = [];
            foreach ($attributeHeaders as $col => $columnName) {
                $attributes[$columnName] = $this->cellValue($row[$col] ?? null);
            }

            $wkt = $this->resolveWkt($row, $geoColumns);
            if (! $wkt) {
                continue;
            }

            $batch[] = ['attributes' => $attributes, 'wkt' => $wkt];

            if (count($batch) >= $chunkSize) {
                $inserted += $this->insertBatch($tableName, $attributeHeaders, $batch, $srid);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $inserted += $this->insertBatch($tableName, $attributeHeaders, $batch, $srid);
        }

        if ($inserted === 0) {
            DB::statement("DROP TABLE IF EXISTS {$tableName} CASCADE");
            throw new Exception('No valid rows with geometry were found in the Excel file.');
        }

        DB::statement("CREATE INDEX IF NOT EXISTS {$tableName}_geom_idx ON {$tableName} USING GIST (geom)");

        $geometryType = $dataImportService->getTableGeometryType($tableName) ?? 'Point';
        $featureCount = $dataImportService->getTableFeatureCount($tableName);

        $layerName = pathinfo($import->file_name, PATHINFO_FILENAME) ?: $tableName;
        $layer = Layer::create([
            'user_id' => $import->user_id,
            'organization_id' => $import->organization_id,
            'name' => $layerName,
            'description' => 'Imported from Excel: '.$import->file_name,
            'table_name' => $tableName,
            'geometry_type' => $geometryType,
            'feature_count' => $featureCount,
            'style_config' => [],
            'metadata' => [
                'source' => 'excel',
                'import_id' => $import->id,
                'geometry_mode' => $geoColumns['mode'],
            ],
        ]);

        Log::info('Excel import completed', [
            'import_id' => $import->id,
            'table_name' => $tableName,
            'layer_id' => $layer->id,
            'feature_count' => $featureCount,
        ]);

        return [
            'table_name' => $tableName,
            'geometry_type' => $geometryType,
            'feature_count' => $featureCount,
            'layer' => $layer,
        ];
    }

    /**
     * @param  array<string, mixed>  $headerRow
     * @return array<string, string> column letter => sanitized name
     */
    protected function normalizeHeaders(array $headerRow): array
    {
        $headers = [];
        $used = [];

        foreach ($headerRow as $col => $value) {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                continue;
            }

            $name = Str::slug($raw, '_');
            $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?: 'col_'.$col;
            if (in_array($name, ['id', 'geom', 'geometry'], true)) {
                $name = 'attr_'.$name;
            }

            $base = $name;
            $i = 1;
            while (in_array($name, $used, true)) {
                $name = $base.'_'.$i++;
            }

            $used[] = $name;
            $headers[$col] = $name;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{mode: ?string, lat: ?string, lon: ?string, wkt: ?string}
     */
    protected function detectGeometryColumns(array $headers): array
    {
        $lat = null;
        $lon = null;
        $wkt = null;

        foreach ($headers as $col => $name) {
            $lower = strtolower($name);
            if (in_array($lower, ['lat', 'latitude', 'y'], true)) {
                $lat = $col;
            } elseif (in_array($lower, ['lon', 'lng', 'long', 'longitude', 'x'], true)) {
                $lon = $col;
            } elseif (in_array($lower, ['wkt', 'geom', 'geometry', 'shape'], true)) {
                $wkt = $col;
            }
        }

        if ($wkt) {
            return ['mode' => 'wkt', 'lat' => null, 'lon' => null, 'wkt' => $wkt];
        }

        if ($lat && $lon) {
            return ['mode' => 'latlon', 'lat' => $lat, 'lon' => $lon, 'wkt' => null];
        }

        return ['mode' => null, 'lat' => null, 'lon' => null, 'wkt' => null];
    }

    /**
     * @param  list<string>  $attributeHeaders keyed by column letter
     */
    protected function createTable(string $tableName, array $attributeHeaders): void
    {
        $columnsSql = ['id BIGSERIAL PRIMARY KEY'];

        foreach ($attributeHeaders as $columnName) {
            $columnsSql[] = '"'.$columnName.'" TEXT';
        }

        $columnsSql[] = 'geom geometry(Geometry, 4326)';

        DB::statement("DROP TABLE IF EXISTS {$tableName} CASCADE");
        DB::statement('CREATE TABLE '.$tableName.' ('.implode(', ', $columnsSql).')');
    }

    /**
     * @param  array<string, string>  $attributeHeaders
     * @param  list<array{attributes: array<string, mixed>, wkt: string}>  $batch
     */
    protected function insertBatch(string $tableName, array $attributeHeaders, array $batch, int $srid): int
    {
        $attrNames = array_values($attributeHeaders);
        $count = 0;

        foreach ($batch as $item) {
            $cols = [];
            $placeholders = [];
            $bindings = [];

            foreach ($attrNames as $name) {
                $cols[] = '"'.$name.'"';
                $placeholders[] = '?';
                $bindings[] = $item['attributes'][$name] ?? null;
            }

            $cols[] = 'geom';
            $placeholders[] = "ST_SetSRID(ST_GeomFromText(?), {$srid})";
            $bindings[] = $item['wkt'];

            DB::insert(
                'INSERT INTO '.$tableName.' ('.implode(', ', $cols).') VALUES ('.implode(', ', $placeholders).')',
                $bindings
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{mode: ?string, lat: ?string, lon: ?string, wkt: ?string}  $geoColumns
     */
    protected function resolveWkt(array $row, array $geoColumns): ?string
    {
        if ($geoColumns['mode'] === 'wkt') {
            $wkt = trim((string) ($row[$geoColumns['wkt']] ?? ''));

            return $wkt !== '' ? $wkt : null;
        }

        if ($geoColumns['mode'] === 'latlon') {
            $lat = $row[$geoColumns['lat']] ?? null;
            $lon = $row[$geoColumns['lon']] ?? null;

            if ($lat === null || $lon === null || $lat === '' || $lon === '') {
                return null;
            }

            if (! is_numeric($lat) || ! is_numeric($lon)) {
                return null;
            }

            return sprintf('POINT(%s %s)', (float) $lon, (float) $lat);
        }

        return null;
    }

    protected function cellValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
