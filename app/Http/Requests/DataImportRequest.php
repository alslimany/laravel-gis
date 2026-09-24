<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class DataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) floor(config('dataimport.max_file_size') / 1024);

        $fileRules = [
            'file',
            "max:{$maxKilobytes}",
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                $extension = strtolower($value->getClientOriginalExtension());
                $allowed = config('dataimport.allowed_extensions', []);

                if (! in_array($extension, $allowed, true)) {
                    $fail('Use a zipped shapefile, KML, KMZ, GeoJSON, CSV, or Excel file. '.$extension.' is not a supported package.');
                }
            },
        ];

        return [
            'file' => array_merge(['required_without:files'], $fileRules),
            'files' => ['required_without:file', 'array'],
            'files.*' => $fileRules,
            'additional_files' => 'nullable|array',
            'additional_files.*' => $fileRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limit = (int) round(config('dataimport.max_file_size') / 1048576);

        return [
            'file.required_without' => 'Choose a dataset to add.',
            'files.required_without' => 'Choose a dataset to add.',
            'file.max' => "This file is larger than the {$limit} MB import limit.",
            'files.*.max' => "A file in this package is larger than the {$limit} MB import limit.",
            'additional_files.*.max' => "A shapefile part is larger than the {$limit} MB import limit.",
        ];
    }
}
