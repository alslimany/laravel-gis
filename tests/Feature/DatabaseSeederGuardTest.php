<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_refuses_demo_users_outside_local(): void
    {
        $this->app['env'] = 'production';

        try {
            (new DatabaseSeeder)->run();
            $this->fail('DatabaseSeeder should refuse demo users when APP_ENV is not local.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_ENV is not local', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
        $this->assertDatabaseHas('roles', [
            'name' => 'admin',
        ]);
    }

    public function test_database_seeder_refuses_demo_users_when_app_env_is_testing(): void
    {
        $this->assertSame('testing', $this->app['env']);

        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('DatabaseSeeder should refuse demo users when APP_ENV is testing.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('local-setup only', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }
}
