<?php

namespace Database\Factories;

use App\Domains\Master\TyreLayout\Enums\TyreAxleConfiguration;
use App\Models\OwnerTyreLayout;
use App\Models\OwnerTyreLayoutAxle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerTyreLayoutAxle>
 */
class OwnerTyreLayoutAxleFactory extends Factory
{
    protected $model = OwnerTyreLayoutAxle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tyre_layout_id' => OwnerTyreLayout::factory(),
            'sequence' => 1,
            'configuration' => TyreAxleConfiguration::Single,
        ];
    }
}
