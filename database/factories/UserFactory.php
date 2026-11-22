<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\IdentityType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
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
            'identity_type' => IdentityType::Customer,
            'is_super_admin' => false,
            'deactivated_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'identity_type' => IdentityType::Customer,
            'is_super_admin' => false,
        ]);
    }

    /**
     * Ordinary Internal identity without Super Admin privilege.
     * Configurable Internal roles are assigned in P2-WS-3.
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'identity_type' => IdentityType::Internal,
            'is_super_admin' => false,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'identity_type' => IdentityType::Internal,
            'is_super_admin' => true,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }
}
