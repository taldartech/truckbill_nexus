<?php

namespace Database\Factories;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'custom.'.Str::lower(Str::random(8));

        return [
            'domain' => PermissionDomain::Customer,
            'name' => fake()->unique()->jobTitle(),
            'slug' => $slug,
            'description' => fake()->sentence(),
            'is_default' => false,
            'is_system' => false,
            'deactivated_at' => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => PermissionDomain::Internal,
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => PermissionDomain::Customer,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }
}
