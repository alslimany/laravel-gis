<?php

namespace App\Http\Requests;

use App\Models\SolutionPackDefinition;
use App\SolutionPacks\DefinitionValidator;
use App\SolutionPacks\SolutionPack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates pack metadata and structure for create/update via the builder.
 */
class SolutionPackRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership before validation (#66): another org's pack is a 404,
        // never a 302 with validation errors that would confirm it exists.
        $pack = $this->route('solutionPack');
        if ($pack instanceof SolutionPackDefinition
            && $pack->organization_id !== $this->user()?->organization_id) {
            abort(404);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        $unique = Rule::unique('solution_pack_definitions', 'slug')
            ->where('organization_id', $organizationId);

        $routePack = $this->route('solutionPack');
        if ($routePack instanceof SolutionPackDefinition) {
            $unique->ignore($routePack);
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(array_map(fn (SolutionPack $pack) => $pack->slug(), SolutionPack::all())),
                $unique,
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'definition' => ['required', 'array'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        $definition = $this->input('definition');
        if (! is_array($definition)) {
            return [];
        }

        $errors = app(DefinitionValidator::class)->validate($definition);

        return collect($errors)
            ->map(fn (string $message, string $key) => fn ($validator) => $validator->errors()->add($key, $message))
            ->values()
            ->all();
    }
}
