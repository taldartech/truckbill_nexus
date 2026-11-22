<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Station;
use App\Models\StationDefaultTransporter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StationDefaultTransporter>
 */
class StationDefaultTransporterFactory extends Factory
{
    protected $model = StationDefaultTransporter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'company_id' => Company::factory(),
            'branch_id' => null,
            'default_transporter_party_id' => null,
        ];
    }
}
