<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EnquiryExternalReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryExternalReference> */
class EnquiryExternalReferenceFactory extends Factory
{
    protected $model = EnquiryExternalReference::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'external_reference' => 'ext-'.Str::lower(Str::random(12)),
            'enquiry_id' => null,
            'source' => 'website',
        ];
    }
}
