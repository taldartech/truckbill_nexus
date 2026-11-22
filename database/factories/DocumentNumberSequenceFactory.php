<?php

namespace Database\Factories;

use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Models\Company;
use App\Models\DocumentNumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentNumberSequence> */
class DocumentNumberSequenceFactory extends Factory
{
    protected $model = DocumentNumberSequence::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'document_type' => DocumentNumberType::Enquiry,
            'scope_type' => DocumentNumberScopeType::Company,
            'branch_id' => null,
            'next_value' => 1,
        ];
    }
}
