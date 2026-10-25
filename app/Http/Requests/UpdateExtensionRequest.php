<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->user()?->organization;

        return $organization !== null && Gate::allows('plugins.toggle', $organization);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
