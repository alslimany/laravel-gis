<?php

namespace App\Services;

use App\Models\FeatureAttachment;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FormSubmissionService
{
    /**
     * Client extension => detected MIME types accepted on public submit.
     *
     * @var array<string, list<string>>
     */
    public const ATTACHMENT_MIMES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /**
     * @var list<string>
     */
    protected const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'dll', 'so', 'sh', 'bash', 'bat', 'cmd', 'com',
        'js', 'mjs', 'html', 'htm', 'svg', 'xhtml', 'htaccess',
    ];

    public function __construct(protected FeatureService $features) {}

    /**
     * Persist a public submission. Linked forms also write a layer feature.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{submission: FormSubmission, feature: ?array}
     */
    public function record(
        Form $form,
        array $attributes,
        ?string $wkt,
        ?float $latitude,
        ?float $longitude,
        ?UploadedFile $file,
        ?int $userId
    ): array {
        return DB::transaction(function () use ($form, $attributes, $wkt, $latitude, $longitude, $file, $userId) {
            $layer = $form->layer;
            $feature = null;
            $featureId = null;

            if ($layer) {
                $feature = $this->features->create(
                    $layer,
                    $this->onlySchemaAttributes($form, $attributes),
                    $this->featureWkt($layer, $wkt)
                );
                $featureId = $feature['id'] ?? ($feature['properties']['id'] ?? null);
                $featureId = $featureId !== null ? (int) $featureId : null;
            }

            $attachment = $this->storeAttachment($form, $featureId, $file, $userId);

            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'organization_id' => $form->organization_id,
                'layer_id' => $layer?->id,
                'feature_id' => $featureId,
                'user_id' => $userId,
                'attributes' => $this->onlySchemaAttributes($form, $attributes),
                'geometry_wkt' => $wkt,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'attachment_name' => $attachment['name'] ?? null,
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_mime' => $attachment['mime'] ?? null,
                'attachment_size' => $attachment['size'] ?? null,
            ]);

            return [
                'submission' => $submission,
                'feature' => $feature,
            ];
        });
    }

    /**
     * Create an organization layer whose columns match the form schema.
     *
     * @param  list<array<string, mixed>>  $schema
     * @return array{layer: Layer, schema: list<array<string, mixed>>}
     */
    public function createLayerFromSchema(
        User $user,
        string $name,
        ?string $description,
        array $schema,
        bool $collectGeometry
    ): array {
        $schema = $this->reserveColumnNames($schema);
        $table = $this->uniqueTableName();
        $driver = DB::getDriverName();

        $columns = [
            $driver === 'pgsql'
                ? '"id" BIGSERIAL PRIMARY KEY'
                : '"id" INTEGER PRIMARY KEY AUTOINCREMENT',
        ];

        foreach ($schema as $field) {
            $columns[] = $this->quoteIdent($field['name']).' TEXT NULL';
        }

        if ($collectGeometry) {
            $columns[] = $driver === 'pgsql'
                ? 'geom geometry(Geometry, 4326)'
                : 'geom TEXT NULL';
        }

        DB::statement('CREATE TABLE '.$this->quoteIdent($table).' ('.implode(', ', $columns).')');

        if ($collectGeometry && $driver === 'pgsql') {
            DB::statement('CREATE INDEX '.$table.'_geom_gix ON '.$this->quoteIdent($table).' USING GIST (geom)');
        }

        $layer = Layer::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => $name,
            'description' => $description ?: 'Created from form '.$name,
            'table_name' => $table,
            'geometry_type' => $collectGeometry ? 'Point' : null,
            'feature_count' => 0,
            'published' => false,
            'metadata' => ['source' => 'form'],
        ]);

        foreach ($schema as $index => $field) {
            LayerField::create([
                'layer_id' => $layer->id,
                'name' => $field['name'],
                'alias' => $field['label'] ?? $field['name'],
                'type' => match ($field['type'] ?? 'text') {
                    'number' => 'number',
                    'checkbox' => 'boolean',
                    'date' => 'date',
                    default => 'string',
                },
                'domain_values' => ($field['type'] ?? '') === 'select' ? ($field['options'] ?? []) : null,
                'required' => (bool) ($field['required'] ?? false),
                'sort_order' => $index,
            ]);
        }

        return ['layer' => $layer, 'schema' => $schema];
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @return list<array<string, mixed>>
     */
    public function normalizeSchema(array $schema): array
    {
        return array_values(array_filter(array_map(function ($field) {
            if (! is_array($field) || empty($field['name'])) {
                return null;
            }

            $type = $field['type'] ?? 'text';
            if (! in_array($type, ['text', 'textarea', 'number', 'select', 'checkbox', 'date'], true)) {
                $type = 'text';
            }

            return [
                'name' => preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $field['name']),
                'label' => $field['label'] ?? $field['name'],
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options' => array_values(is_array($field['options'] ?? null) ? $field['options'] : []),
            ];
        }, $schema)));
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>}
     */
    public function submissionTable(Form $form): array
    {
        $submissions = $form->submissions()->orderBy('id')->get();
        $fields = [];
        foreach ($form->schema ?? [] as $field) {
            $name = $field['name'] ?? null;
            if (is_string($name) && $name !== '' && ! in_array($name, $fields, true)) {
                $fields[] = $name;
            }
        }

        $extra = [];
        foreach ($submissions as $submission) {
            foreach (array_keys($submission->attributes ?? []) as $key) {
                if (! in_array($key, $fields, true) && ! in_array($key, $extra, true)) {
                    $extra[] = $key;
                }
            }
        }

        $headers = array_merge(
            ['id', 'submitted_at', 'feature_id'],
            $fields,
            $extra,
            ['latitude', 'longitude', 'geometry_wkt']
        );

        $rows = [];
        foreach ($submissions as $submission) {
            $row = [
                'id' => $submission->id,
                'submitted_at' => $submission->created_at?->toDateTimeString(),
                'feature_id' => $submission->feature_id,
                'latitude' => $submission->latitude,
                'longitude' => $submission->longitude,
                'geometry_wkt' => $submission->geometry_wkt,
            ];
            foreach ($submission->attributes ?? [] as $key => $value) {
                $row[$key] = $value;
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>}|null
     */
    public function layerTable(Layer $layer): ?array
    {
        $table = $layer->table_name;
        if (! $table || ! Schema::hasTable($table)) {
            return null;
        }

        $rows = $this->layerRows($table);
        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (! in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }

        if ($headers === []) {
            $headers = ['id'];
            foreach ($layer->fields()->orderBy('sort_order')->get() as $field) {
                $headers[] = $field->name;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public function toCsv(array $headers, array $rows): string
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $this->csvValue($row[$header] ?? null);
            }
            fputcsv($output, $line);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public function saveSpreadsheet(string $title, array $headers, array $rows, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($title));

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }

        $rowNum = 2;
        foreach ($rows as $row) {
            foreach ($headers as $index => $header) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($index + 1).$rowNum,
                    $this->cellValue($row[$header] ?? null)
                );
            }
            $rowNum++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function onlySchemaAttributes(Form $form, array $attributes): array
    {
        $clean = [];
        foreach ($form->schema ?? [] as $field) {
            $name = $field['name'] ?? null;
            if (! is_string($name) || $name === '' || ! array_key_exists($name, $attributes)) {
                continue;
            }
            $clean[$name] = $attributes[$name];
        }

        return $clean;
    }

    protected function featureWkt(Layer $layer, ?string $wkt): ?string
    {
        if (! $wkt) {
            return null;
        }

        if (Form::layerRequiresGeometry($layer) || $this->layerHasGeometryColumn($layer)) {
            return $wkt;
        }

        return null;
    }

    public function layerHasGeometryColumn(Layer $layer): bool
    {
        $table = $layer->table_name;
        if (! $table) {
            return false;
        }

        try {
            return Schema::hasColumn($table, 'geom') || Schema::hasColumn($table, 'geometry');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{name: string, path: string, mime: ?string, size: int}|array{}
     */
    protected function storeAttachment(Form $form, ?int $featureId, ?UploadedFile $file, ?int $userId): array
    {
        if (! $file) {
            return [];
        }

        $safe = $this->acceptedAttachment($file);

        $directory = $featureId && $form->layer_id
            ? "attachments/{$form->layer_id}/{$featureId}"
            : "attachments/forms/{$form->id}";
        $storedName = Str::uuid().'_'.$safe['name'];
        $path = $file->storeAs($directory, $storedName, 'local');

        if ($featureId && $form->layer_id) {
            FeatureAttachment::create([
                'layer_id' => $form->layer_id,
                'feature_id' => $featureId,
                'user_id' => $userId,
                'file_name' => $safe['name'],
                'file_path' => $path,
                'mime_type' => $safe['mime'],
                'file_size' => $file->getSize() ?: 0,
            ]);
        }

        return [
            'name' => $safe['name'],
            'path' => $path,
            'mime' => $safe['mime'],
            'size' => $file->getSize() ?: 0,
        ];
    }

    /**
     * Accept a public attachment only when its extension and detected MIME are allowlisted.
     *
     * @return array{name: string, extension: string, mime: string}|null
     */
    public function acceptedAttachment(?UploadedFile $file): ?array
    {
        if (! $file) {
            return null;
        }

        $raw = str_replace("\0", '', $file->getClientOriginalName());
        $raw = basename(str_replace('\\', '/', $raw));
        $parts = $raw === '' ? [] : explode('.', $raw);
        $extension = strtolower((string) preg_replace('/[^a-z0-9]/', '', (string) array_pop($parts)));

        foreach ($parts as $part) {
            $token = strtolower((string) preg_replace('/[^a-z0-9]/', '', $part));
            if ($token !== '' && in_array($token, self::DANGEROUS_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'attachment' => 'This file type is not allowed.',
                ]);
            }
        }

        if ($extension === '' || ! isset(self::ATTACHMENT_MIMES[$extension])) {
            throw ValidationException::withMessages([
                'attachment' => 'This file type is not allowed.',
            ]);
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, self::ATTACHMENT_MIMES[$extension], true)) {
            throw ValidationException::withMessages([
                'attachment' => 'This file type is not allowed.',
            ]);
        }

        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '_', implode('.', $parts)) ?? '';
        $stem = trim((string) preg_replace('/_+/', '_', $stem), '._-');
        if ($stem === '' || $stem === '.' || $stem === '..') {
            $stem = 'attachment';
        }

        return [
            'name' => substr($stem, 0, 80).'.'.$extension,
            'extension' => $extension,
            'mime' => $mime,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function layerRows(string $table): array
    {
        $geom = null;
        if (Schema::hasColumn($table, 'geom')) {
            $geom = 'geom';
        } elseif (Schema::hasColumn($table, 'geometry')) {
            $geom = 'geometry';
        }

        if ($geom && DB::getDriverName() === 'pgsql') {
            $quotedTable = $this->quoteIdent($table);
            $quotedGeom = $this->quoteIdent($geom);
            $records = DB::select('SELECT *, ST_AsText('.$quotedGeom.') AS geometry_wkt FROM '.$quotedTable);

            return array_map(function ($row) use ($geom) {
                $data = (array) $row;
                unset($data[$geom]);

                return $this->plainRow($data);
            }, $records);
        }

        return DB::table($table)->orderBy('id')->get()->map(function ($row) {
            return $this->plainRow((array) $row);
        })->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function plainRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_resource($value)) {
                unset($row[$key]);
            }
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @return list<array<string, mixed>>
     */
    protected function reserveColumnNames(array $schema): array
    {
        $used = [];
        $reserved = [];

        foreach ($schema as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (in_array(strtolower($name), ['id', 'geom', 'geometry'], true)) {
                $name = 'attr_'.$name;
            }

            $base = $name;
            $suffix = 2;
            while (in_array(strtolower($name), $used, true)) {
                $name = $base.'_'.$suffix;
                $suffix++;
            }

            $used[] = strtolower($name);
            $field['name'] = $name;
            $reserved[] = $field;
        }

        return $reserved;
    }

    protected function uniqueTableName(): string
    {
        do {
            $table = 'form_layer_'.strtolower(Str::random(10));
        } while (Schema::hasTable($table));

        return $table;
    }

    protected function quoteIdent(string $identifier): string
    {
        return '"'.str_replace('"', '', $identifier).'"';
    }

    protected function csvValue(mixed $value): string
    {
        $string = $this->stringify($value);
        $probe = ltrim($string, " \t\r\n");
        if ($probe !== '' && in_array($probe[0], ['=', '+', '-', '@'], true)) {
            return "'".$string;
        }

        return $string;
    }

    protected function cellValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }

    protected function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return (string) $value;
    }

    protected function sheetTitle(string $title): string
    {
        $clean = trim((string) preg_replace('/[\\\\\\/:?*\\[\\]]/', ' ', $title));

        return substr($clean !== '' ? $clean : 'Form', 0, 31);
    }
}
