<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => null,
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'contact_number' => fake()->numerify('9#########'),
            'email' => fake()->safeEmail(),
            'gstin' => null,
            'pan' => null,
            'is_gst_applicable' => true,
            'state' => 'Maharashtra',
            'city' => 'Mumbai',
            'address' => fake()->address(),
        ];
    }
}
