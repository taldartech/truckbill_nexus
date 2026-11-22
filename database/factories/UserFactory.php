<?php

namespace Database\Factories;

use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\Enums\InternalRole;
use App\Models\User;
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
            'internal_role' => null,
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
            'internal_role' => null,
        ]);
    }

    public function internal(?InternalRole $role = InternalRole::SupportExecutive): static
    {
        return $this->state(fn (array $attributes) => [
            'identity_type' => IdentityType::Internal,
            'internal_role' => $role,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->internal(InternalRole::SuperAdmin);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }
}
