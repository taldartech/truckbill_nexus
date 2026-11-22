<?php

namespace Database\Factories;

use App\Domains\Enquiry\Enums\EnquirySource;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryGoodsRow;
use App\Models\EnquiryQuotation;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    protected $model = Enquiry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => null,
            'source' => EnquirySource::Manual,
            'enquiry_number' => 'ENQ-'.fake()->unique()->numerify('######'),
            'enquiry_date' => now()->toDateString(),
            'customer_party_id' => null,
            'external_customer_name' => fake()->name(),
            'external_customer_phone' => fake()->numerify('9#########'),
            'external_customer_email' => fake()->safeEmail(),
            'pickup_location' => fake()->city(),
            'delivery_location' => fake()->city(),
            'vehicle_type_id' => OwnerCatalogueItem::factory()->state([
                'catalogue_type' => OwnerCatalogueType::TruckType->value,
                'code' => strtoupper(fake()->unique()->bothify('TT###')),
            ]),
            'vehicle_quantity' => 1,
            'expected_loading_date' => now()->addDays(3)->toDateString(),
            'instructions' => null,
            'assigned_user_id' => null,
            'status' => EnquiryStatus::New,
            'created_by_user_id' => null,
            'external_reference' => null,
            'converted_at' => null,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => [
            'source' => EnquirySource::Website,
            'branch_id' => null,
            'assigned_user_id' => null,
            'status' => EnquiryStatus::New,
        ]);
    }

    public function assigned(?Branch $branch = null): static
    {
        return $this->state(function (array $attributes) use ($branch): array {
            $companyId = $attributes['company_id'] instanceof Company
                ? $attributes['company_id']->id
                : $attributes['company_id'];

            $resolvedBranch = $branch ?? Branch::factory()->create([
                'company_id' => $companyId,
            ]);

            return [
                'company_id' => $resolvedBranch->company_id,
                'branch_id' => $resolvedBranch->id,
                'source' => EnquirySource::Manual,
                'status' => EnquiryStatus::UnderReview,
            ];
        });
    }

    public function withGoods(int $rows = 1): static
    {
        return $this->afterCreating(function (Enquiry $enquiry) use ($rows): void {
            for ($i = 1; $i <= $rows; $i++) {
                EnquiryGoodsRow::factory()->create([
                    'company_id' => $enquiry->company_id,
                    'enquiry_id' => $enquiry->id,
                    'position' => $i,
                ]);
            }
        });
    }

    public function withQuotations(int $count = 1): static
    {
        return $this->afterCreating(function (Enquiry $enquiry) use ($count): void {
            for ($i = 0; $i < $count; $i++) {
                $transporter = Party::factory()->create(['company_id' => $enquiry->company_id]);
                EnquiryQuotation::factory()->create([
                    'company_id' => $enquiry->company_id,
                    'enquiry_id' => $enquiry->id,
                    'transporter_party_id' => $transporter->id,
                ]);
            }
        });
    }

    public function converted(): static
    {
        return $this->state(fn (): array => [
            'status' => EnquiryStatus::Converted,
            'converted_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => EnquiryStatus::Cancelled,
        ]);
    }

    public function ownVehicle(): static
    {
        return $this->state(fn (): array => [
            'status' => EnquiryStatus::UnderReview,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(function () use ($company): array {
            $ownerId = $company->owner_user_id;
            $vehicleType = OwnerCatalogueItem::factory()->create([
                'owner_user_id' => $ownerId,
                'catalogue_type' => OwnerCatalogueType::TruckType->value,
                'code' => strtoupper(fake()->unique()->bothify('TT###')),
            ]);

            return [
                'company_id' => $company->id,
                'vehicle_type_id' => $vehicleType->id,
            ];
        });
    }
}
