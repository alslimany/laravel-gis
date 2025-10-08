<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DataImport>
 */
class DataImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fileTypes = ['shapefile', 'geojson', 'kml', 'csv'];
        $fileType = fake()->randomElement($fileTypes);

        $extensions = [
            'shapefile' => 'shp',
            'geojson' => 'geojson',
            'kml' => 'kml',
            'csv' => 'csv',
        ];

        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'file_name' => fake()->word().'.'.$extensions[$fileType],
            'file_path' => 'imports/'.fake()->uuid().'.'.$extensions[$fileType],
            'file_type' => $fileType,
            'file_size' => fake()->numberBetween(1000, 10000000),
            'status' => fake()->randomElement(['pending', 'processing', 'completed', 'failed']),
            'table_name' => null,
            'geometry_type' => null,
            'feature_count' => null,
            'progress' => 0,
            'error_message' => null,
            'metadata' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the import is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'table_name' => 'import_'.fake()->word().'_'.substr(md5(fake()->uuid()), 0, 8),
            'geometry_type' => fake()->randomElement(['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon']),
            'feature_count' => fake()->numberBetween(10, 10000),
            'progress' => 100,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Indicate that the import is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'progress' => fake()->numberBetween(10, 90),
            'started_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Indicate that the import has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Failed to process file: '.fake()->sentence(),
            'completed_at' => now(),
        ]);
    }
}
