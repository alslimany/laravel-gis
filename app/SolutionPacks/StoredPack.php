<?php

namespace App\SolutionPacks;

use App\Models\Organization;
use App\Models\SolutionPackDefinition;

/**
 * A solution pack defined through the UI and stored as JSON.
 * Behaves exactly like a built-in pack for the installer.
 */
class StoredPack extends SolutionPack
{
    public function __construct(
        private readonly string $storedSlug,
        private readonly string $storedName,
        private readonly string $storedDescription,
        private readonly array $storedDefinition,
    ) {}

    public static function fromModel(SolutionPackDefinition $model): self
    {
        return new self(
            $model->slug,
            $model->name,
            (string) $model->description,
            is_array($model->definition) ? $model->definition : [],
        );
    }

    /**
     * Resolve a stored pack owned by the given organization.
     */
    public static function findFor(Organization $organization, string $slug): ?self
    {
        $model = SolutionPackDefinition::query()
            ->where('organization_id', $organization->id)
            ->where('slug', $slug)
            ->first();

        return $model ? self::fromModel($model) : null;
    }

    public function slug(): string
    {
        return $this->storedSlug;
    }

    public function name(): string
    {
        return $this->storedName;
    }

    public function description(): string
    {
        return $this->storedDescription;
    }

    public function definition(): array
    {
        return $this->storedDefinition;
    }
}
