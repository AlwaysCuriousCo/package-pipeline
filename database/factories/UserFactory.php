<?php

namespace Database\Factories;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Factories\Factory;
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
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
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

    /**
     * Give the user Shield's super admin role, holding every permission that
     * exists. This mirrors what `php artisan admin:create` grants, so it is
     * the state to act as when a test exercises the admin panel.
     */
    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $role = Utils::createRole();

            $role->syncPermissions(
                Utils::getPermissionModel()::query()
                    ->where('guard_name', $role->guard_name)
                    ->pluck('id')
            );

            $user->assignRole($role);
        });
    }
}
