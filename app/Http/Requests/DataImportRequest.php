<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DataImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxFileSize = config('dataimport.max_file_size') / 1024; // Convert to KB for validation

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxFileSize}",
                'mimes:shp,shx,dbf,prj,cpg,geojson,json,kml,kmz,csv',
            ],
            'additional_files' => 'nullable|array',
            'additional_files.*' => [
                'file',
                "max:{$maxFileSize}",
                'mimes:shp,shx,dbf,prj,cpg',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The uploaded file is not valid.',
            'file.max' => 'The file size exceeds the maximum allowed size.',
            'file.mimes' => 'The file type is not supported. Please upload a valid spatial file format.',
        ];
    }
}
