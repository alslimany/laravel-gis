<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $longitude = fake()->longitude();
        $latitude = fake()->latitude();

        $location = DB::connection()->getDriverName() === 'pgsql'
            ? DB::raw("ST_MakePoint({$longitude}, {$latitude})::geography")
            : null; // For SQLite, just use null

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Test-only factory password. Not a credential for shared or public hosts.
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'location' => $location,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
