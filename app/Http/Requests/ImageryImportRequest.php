<?php

namespace App\Http\Requests;

use App\Services\ImageryImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class ImageryImportRequest extends FormRequest
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
        $maxKilobytes = (int) floor(config('imagery.max_file_size') / 1024);

        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => [
                'file',
                "max:{$maxKilobytes}",
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    $allowed = config('imagery.allowed_extensions', []);

                    if (! in_array($extension, $allowed, true)) {
                        $fail($extension.' is not an imagery file. Use GeoTIFF, or JPEG/PNG with a world file and .prj.');
                    }
                },
            ],
            'acquired_at' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'layer_id' => ['nullable', 'integer', 'exists:layers,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $uploads = $this->file('files', []);
            if (! is_array($uploads)) {
                return;
            }

            $names = [];
            foreach ($uploads as $upload) {
                if ($upload instanceof UploadedFile) {
                    $names[] = $upload->getClientOriginalName();
                }
            }

            $error = ImageryImportService::georeferenceError($names);
            if ($error !== null) {
                $validator->errors()->add('files', $error);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limit = (int) round(config('imagery.max_file_size') / 1048576);

        return [
            'files.required' => 'Choose a georeferenced image.',
            'acquired_at.required' => 'Enter the date this scene was captured. That date decides which image is on top where scenes overlap.',
            'files.*.max' => "This image is larger than the {$limit} MB limit.",
        ];
    }
}
