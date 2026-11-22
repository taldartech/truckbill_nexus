<?php

namespace Database\Factories;

use App\Models\GstRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GstRate>
 */
class GstRateFactory extends Factory
{
    protected $model = GstRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'GST18',
            'name' => 'GST 18%',
            'rate_percent' => '18.000',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'version' => 1,
            'is_active' => true,
        ];
    }
}
