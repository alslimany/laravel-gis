<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create test user with specific location
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'location' => DB::raw("ST_MakePoint(-122.4194, 37.7749)::geography"), // San Francisco
        ]);

        // Create organization for the test user
        $organization = Organization::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Organization',
            'description' => 'A test organization for spatial data',
        ]);

        // Create project for the organization with bounding box
        Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Test Project',
            'description' => 'A test project with spatial bounding box',
            'bounding_box' => DB::raw("ST_GeomFromText('POLYGON((-122.5 37.7, -122.3 37.7, -122.3 37.8, -122.5 37.8, -122.5 37.7))', 4326)"),
        ]);

        // Create additional users with organizations and projects
        User::factory(5)
            ->has(
                Organization::factory()
                    ->count(2)
                    ->has(Project::factory()->count(3))
            )
            ->create();
    }
}
