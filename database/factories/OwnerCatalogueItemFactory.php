<?php

namespace Database\Factories;

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Models\OwnerCatalogueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerCatalogueItem>
 */
class OwnerCatalogueItemFactory extends Factory
{
    protected $model = OwnerCatalogueItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory()->customer(),
            'catalogue_type' => OwnerCatalogueType::GoodsType->value,
            'code' => null,
            'name' => fake()->words(2, true),
            'attributes' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
