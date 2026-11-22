<?php

namespace Database\Factories;

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Models\Branch;
use App\Models\LorryReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LorryReceipt>
 */
class LorryReceiptFactory extends Factory
{
    protected $model = LorryReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::factory()->create();

        return [
            'id' => (string) Str::uuid(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'workflow_context' => LrWorkflowContext::Broker,
            'lr_number' => 'LR-'.fake()->unique()->numerify('######'),
            'lr_date' => now()->toDateString(),
            'from_text' => fake()->city(),
            'to_text' => fake()->city(),
            'from_station_id' => null,
            'to_station_id' => null,
            'consignor_party_id' => null,
            'consignee_party_id' => null,
            'status' => LrStatus::Pending,
            'goods_value' => null,
            'freight_total' => '0.00',
            'additional_charges_total' => '0.00',
            'created_by_user_id' => null,
            'delivered_at' => null,
            'cancelled_at' => null,
            'cancellation_remark' => null,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function broker(): static
    {
        return $this->state(fn (): array => [
            'workflow_context' => LrWorkflowContext::Broker,
        ]);
    }

    public function ownFleet(): static
    {
        return $this->state(fn (): array => [
            'workflow_context' => LrWorkflowContext::OwnFleet,
        ]);
    }

    public function partload(): static
    {
        return $this->state(fn (): array => [
            'workflow_context' => LrWorkflowContext::Partload,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => LrStatus::Pending,
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => LrStatus::Delivered,
            'delivered_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => LrStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_remark' => 'Test cancellation',
        ]);
    }
}
