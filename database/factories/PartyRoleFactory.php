<?php

namespace Database\Factories;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Party;
use App\Models\PartyRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartyRole>
 */
class PartyRoleFactory extends Factory
{
    protected $model = PartyRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $party = Party::factory()->create();

        return [
            'id' => (string) Str::uuid(),
            'company_id' => $party->company_id,
            'party_id' => $party->id,
            'role' => PartyRoleType::Customer->value,
            'role_attributes' => [],
            'deactivated_at' => null,
        ];
    }
}
