<?php

namespace Database\Factories;

use App\Models\Map;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Map>
 */
class MapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'viewport' => ['center' => [0, 0], 'zoom' => 2, 'rotation' => 0],
            'basemap' => 'osm',
            'layers' => [],
            'is_public' => false,
        ];
    }
}
