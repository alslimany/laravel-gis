<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles first. Roles are not demo credentials.
        $this->call(RoleSeeder::class);

        // Demo users use the stock factory password. Local setup only.
        if (! app()->environment('local')) {
            $message = 'Refusing to seed demo users because APP_ENV is not local. Demo credentials are local-setup only. RoleSeeder already ran; do not seed demo users on a shared or public host.';
            $this->command?->error($message);

            throw new RuntimeException($message);
        }

        $this->command?->warn('LOCAL SETUP ONLY: seeding demo user test@example.com with the stock factory password. Change that account before any network exposure.');

        // Create test user with specific location
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'location' => DB::raw('ST_MakePoint(-122.4194, 37.7749)::geography'), // San Francisco
        ]);

        // Create organization for the test user
        $organization = Organization::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Organization',
            'description' => 'A test organization for spatial data',
        ]);

        // Assign user to organization
        $user->update(['organization_id' => $organization->id]);

        // Assign admin role to test user
        $adminRole = Role::where('name', 'admin')->first();
        $user->roles()->attach($adminRole);

        // Create project for the organization with bounding box
        Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Test Project',
            'description' => 'A test project with spatial bounding box',
            'bounding_box' => DB::raw("ST_GeomFromText('POLYGON((-122.5 37.7, -122.3 37.7, -122.3 37.8, -122.5 37.8, -122.5 37.7))', 4326)"),
        ]);

        // Create additional users with organizations and projects
        $additionalUsers = User::factory(5)->create();

        foreach ($additionalUsers as $additionalUser) {
            $org = Organization::factory()->create([
                'user_id' => $additionalUser->id,
            ]);

            $additionalUser->update(['organization_id' => $org->id]);

            // Assign viewer role to additional users
            $viewerRole = Role::where('name', 'viewer')->first();
            $additionalUser->roles()->attach($viewerRole);

            // Create projects
            Project::factory(3)->create([
                'organization_id' => $org->id,
            ]);
        }
    }
}
