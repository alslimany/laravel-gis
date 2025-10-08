<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Layer>
 */
class LayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'table_name' => 'layer_' . fake()->unique()->slug(2),
            'geometry_type' => fake()->randomElement(['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon']),
            'feature_count' => fake()->numberBetween(0, 10000),
            'style_config' => [
                'fillColor' => fake()->hexColor(),
                'strokeColor' => fake()->hexColor(),
                'strokeWidth' => fake()->numberBetween(1, 5),
                'fillOpacity' => fake()->randomFloat(1, 0.1, 1.0),
            ],
            'published' => fake()->boolean(30),
            'geoserver_layer_name' => null,
            'geoserver_workspace' => null,
            'published_at' => null,
            'metadata' => [],
        ];
    }

    /**
     * Indicate that the layer is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => true,
            'geoserver_layer_name' => $attributes['table_name'],
            'geoserver_workspace' => 'org_' . fake()->numberBetween(1, 100),
            'published_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    /**
     * Indicate that the layer is unpublished.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => false,
            'geoserver_layer_name' => null,
            'geoserver_workspace' => null,
            'published_at' => null,
        ]);
    }
}
