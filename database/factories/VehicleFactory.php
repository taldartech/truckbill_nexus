<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'registration_number' => strtoupper(fake()->unique()->bothify('MH##??####')),
            'vehicle_type' => 'truck',
            'make' => 'Tata',
            'model' => '407',
            'manufacture_year' => 2020,
            'is_active' => true,
        ];
    }
}
