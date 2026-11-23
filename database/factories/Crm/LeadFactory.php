<?php

namespace Database\Factories\Crm;

use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Models\Crm\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'contact_name' => fake()->name(),
            'email' => $email,
            'mobile' => '98'.fake()->numerify('########'),
            'company_name' => fake()->company(),
            'source' => LeadSource::Website,
            'interested_product' => InterestedProduct::Broker,
            'status' => LeadStatus::New,
            'assigned_to_user_id' => null,
            'created_by_user_id' => User::factory()->superAdmin(),
            'remarks' => null,
            'email_normalized' => Str::lower($email),
            'duplicate_override' => false,
            'duplicate_override_remark' => null,
        ];
    }

    public function qualified(): static
    {
        return $this->state(fn (): array => [
            'status' => LeadStatus::Qualified,
        ]);
    }
}
