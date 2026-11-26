<?php

namespace Database\Factories;

use App\Models\OwnerTyreLayout;
use App\Models\OwnerTyreLayoutPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerTyreLayoutPosition>
 */
class OwnerTyreLayoutPositionFactory extends Factory
{
    protected $model = OwnerTyreLayoutPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tyre_layout_id' => OwnerTyreLayout::factory(),
            'position_key' => 'A1-L',
            'axle_sequence' => 1,
            'side' => 'L',
            'ring' => null,
            'label' => 'Axle 1 Left',
            'is_active' => true,
        ];
    }
}
