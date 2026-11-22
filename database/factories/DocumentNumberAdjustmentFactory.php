<?php

namespace Database\Factories;

use App\Models\DocumentNumberAdjustment;
use App\Models\DocumentNumberSequence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentNumberAdjustment> */
class DocumentNumberAdjustmentFactory extends Factory
{
    protected $model = DocumentNumberAdjustment::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'sequence_id' => DocumentNumberSequence::factory(),
            'previous_next_value' => 1,
            'new_next_value' => 10,
            'actor_user_id' => User::factory()->customer(),
            'remark' => 'Adjust sequence',
            'adjusted_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (DocumentNumberAdjustment $row): void {
            $seq = DocumentNumberSequence::query()->find($row->sequence_id);
            if ($seq !== null) {
                $row->company_id = $seq->company_id;
            }
        });
    }
}
