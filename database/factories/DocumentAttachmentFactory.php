<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DocumentAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    protected $model = DocumentAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'parent_type' => 'company_record',
            'parent_id' => fn (array $attrs) => $attrs['company_id'],
            'stored_file_id' => (string) Str::uuid(),
            'document_type' => 'general',
            'label' => 'Document',
            'version' => 1,
            'counts_toward_storage' => true,
            'is_current' => true,
        ];
    }
}
