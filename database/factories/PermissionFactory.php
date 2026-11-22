<?php

namespace Database\Factories;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'test.'.Str::lower(Str::random(8));

        return [
            'domain' => PermissionDomain::Customer,
            'module' => 'platform',
            'submodule' => 'operations',
            'name' => fake()->words(2, true),
            'code' => $code,
            'description' => fake()->sentence(),
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
