<?php

namespace Database\Factories;

use App\Domains\Numbering\Enums\DocumentNumberAllocationMode;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Models\Company;
use App\Models\DocumentNumberAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentNumberAllocation> */
class DocumentNumberAllocationFactory extends Factory
{
    protected $model = DocumentNumberAllocation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'document_type' => DocumentNumberType::Enquiry,
            'scope_type' => DocumentNumberScopeType::Company,
            'branch_id' => null,
            'number' => (string) fake()->unique()->numerify('#####'),
            'allocation_mode' => DocumentNumberAllocationMode::Auto,
            'allocated_to_id' => null,
            'allocated_to_type' => null,
        ];
    }
}
