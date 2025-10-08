<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate random bounding box coordinates
        $minLon = fake()->longitude();
        $minLat = fake()->latitude();
        $maxLon = $minLon + fake()->randomFloat(2, 0.01, 1.0);
        $maxLat = $minLat + fake()->randomFloat(2, 0.01, 1.0);

        // Create WKT polygon for bounding box
        $polygon = "POLYGON(({$minLon} {$minLat}, {$maxLon} {$minLat}, {$maxLon} {$maxLat}, {$minLon} {$maxLat}, {$minLon} {$minLat}))";

        $boundingBox = DB::connection()->getDriverName() === 'pgsql'
            ? DB::raw("ST_GeomFromText('{$polygon}', 4326)")
            : null; // For SQLite, just use null

        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'organization_id' => Organization::factory(),
            'bounding_box' => $boundingBox,
        ];
    }
}
