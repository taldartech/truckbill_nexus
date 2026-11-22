<?php

use App\Domains\Consignment\Dependency\CompositeLrDependencyGuard;
use App\Domains\Consignment\Dependency\LrDependencyBlocker;
use App\Domains\Consignment\Dependency\LrDependencyGuard;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Enquiry\Enums\EnquirySource;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Enquiry\Webhook\EnquiryWebhookCredentialService;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Numbering\Exceptions\DocumentNumberConflictException;
use App\Domains\Numbering\Exceptions\DocumentNumberValidationException;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryConversion;
use App\Models\EnquiryConversionLorryReceipt;
use App\Models\EnquiryExternalReference;
use App\Models\EnquiryQuotation;
use App\Models\LorryReceipt;
use App\Models\LrAdditionalCharge;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('phase 4 batch a tables exist after migrate', function () {
    foreach ([
        'enquiries',
        'enquiry_goods_rows',
        'enquiry_assignment_histories',
        'enquiry_follow_ups',
        'enquiry_status_histories',
        'enquiry_quotations',
        'enquiry_quotation_selection_histories',
        'enquiry_quotation_broadcast_requests',
        'enquiry_quotation_broadcast_targets',
        'company_enquiry_webhook_credentials',
        'enquiry_external_references',
        'lorry_receipts',
        'lr_goods_rows',
        'lr_eway_bills',
        'lr_additional_charges',
        'document_number_sequences',
        'document_number_allocations',
        'document_number_adjustments',
        'enquiry_conversions',
        'enquiry_conversion_lorry_receipts',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
});

test('enquiry factory creates company-scoped enquiry with uuid and soft deletes', function () {
    $company = Company::factory()->create();
    $enquiry = Enquiry::factory()->forCompany($company)->unassigned()->withGoods(2)->create();

    expect($enquiry->id)->toBeUuid()
        ->and($enquiry->company_id)->toBe($company->id)
        ->and($enquiry->branch_id)->toBeNull()
        ->and($enquiry->source)->toBe(EnquirySource::Website)
        ->and($enquiry->status)->toBe(EnquiryStatus::New)
        ->and($enquiry->vehicle_quantity)->toBeGreaterThanOrEqual(1)
        ->and($enquiry->goodsRows)->toHaveCount(2);

    $enquiry->delete();
    expect($enquiry->fresh()->trashed())->toBeTrue()
        ->and(Enquiry::withTrashed()->whereKey($enquiry->id)->exists())->toBeTrue();
});

test('enquiry numbers are unique per company among active rows', function () {
    $company = Company::factory()->create();
    Enquiry::factory()->forCompany($company)->create(['enquiry_number' => 'ENQ-UNIQUE-1']);

    $duplicateFailed = false;
    try {
        DB::transaction(function () use ($company): void {
            Enquiry::factory()->forCompany($company)->create(['enquiry_number' => 'ENQ-UNIQUE-1']);
        });
    } catch (QueryException) {
        $duplicateFailed = true;
    }
    expect($duplicateFailed)->toBeTrue();

    $other = Company::factory()->create();
    expect(Enquiry::factory()->forCompany($other)->create(['enquiry_number' => 'ENQ-UNIQUE-1']))
        ->enquiry_number->toBe('ENQ-UNIQUE-1');
});

test('vehicle quantity must be at least one', function () {
    $company = Company::factory()->create();
    $enquiry = Enquiry::factory()->forCompany($company)->make(['vehicle_quantity' => 0]);

    $failed = false;
    try {
        DB::transaction(fn () => $enquiry->save());
    } catch (QueryException) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
});

test('active quotation is unique per transporter and enquiry', function () {
    $company = Company::factory()->create();
    $enquiry = Enquiry::factory()->forCompany($company)->create();
    $transporter = Party::factory()->create(['company_id' => $company->id]);

    EnquiryQuotation::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'enquiry_id' => $enquiry->id,
        'transporter_party_id' => $transporter->id,
        'quoted_freight' => '100.00',
        'selection_status' => 'pending',
    ]);

    $failed = false;
    try {
        DB::transaction(function () use ($company, $enquiry, $transporter): void {
            EnquiryQuotation::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'enquiry_id' => $enquiry->id,
                'transporter_party_id' => $transporter->id,
                'quoted_freight' => '200.00',
                'selection_status' => 'pending',
            ]);
        });
    } catch (QueryException) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
});

test('cross-company party cannot be attached as quotation transporter via composite fk', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $enquiry = Enquiry::factory()->forCompany($companyA)->create();
    $foreignParty = Party::factory()->create(['company_id' => $companyB->id]);

    $failed = false;
    try {
        DB::transaction(function () use ($companyA, $enquiry, $foreignParty): void {
            EnquiryQuotation::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $companyA->id,
                'enquiry_id' => $enquiry->id,
                'transporter_party_id' => $foreignParty->id,
                'quoted_freight' => '100.00',
                'selection_status' => 'pending',
            ]);
        });
    } catch (QueryException) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
});

test('lorry receipt factory supports workflow contexts and statuses', function () {
    $branch = Branch::factory()->create();
    $broker = LorryReceipt::factory()->forBranch($branch)->broker()->pending()->create();
    $own = LorryReceipt::factory()->forBranch($branch)->ownFleet()->create([
        'lr_number' => 'LR-OWN-1',
    ]);
    $part = LorryReceipt::factory()->forBranch($branch)->partload()->delivered()->create([
        'lr_number' => 'LR-PTL-1',
    ]);

    expect($broker->workflow_context)->toBe(LrWorkflowContext::Broker)
        ->and($own->workflow_context)->toBe(LrWorkflowContext::OwnFleet)
        ->and($part->status)->toBe(LrStatus::Delivered)
        ->and(FreightRateType::FixedAmount->value)->toBe('fixed_amount');
});

test('additional charges require positive amount', function () {
    $branch = Branch::factory()->create();
    $lr = LorryReceipt::factory()->forBranch($branch)->create();

    $failed = false;
    try {
        DB::transaction(function () use ($lr): void {
            LrAdditionalCharge::factory()->create([
                'company_id' => $lr->company_id,
                'lorry_receipt_id' => $lr->id,
                'amount' => '0.00',
            ]);
        });
    } catch (QueryException) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
});

test('conversion pivot allows multiple lr links without fixing cardinality', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $enquiry = Enquiry::factory()->forCompany($company)->converted()->create();
    $conversion = EnquiryConversion::factory()->create([
        'company_id' => $company->id,
        'enquiry_id' => $enquiry->id,
    ]);

    $lr1 = LorryReceipt::factory()->forBranch($branch)->create(['lr_number' => 'LR-C-1']);
    $lr2 = LorryReceipt::factory()->forBranch($branch)->create(['lr_number' => 'LR-C-2']);

    EnquiryConversionLorryReceipt::factory()->create([
        'company_id' => $company->id,
        'enquiry_conversion_id' => $conversion->id,
        'lorry_receipt_id' => $lr1->id,
        'position' => 1,
    ]);
    EnquiryConversionLorryReceipt::factory()->create([
        'company_id' => $company->id,
        'enquiry_conversion_id' => $conversion->id,
        'lorry_receipt_id' => $lr2->id,
        'position' => 2,
    ]);

    expect($conversion->lorryReceiptLinks()->count())->toBe(2);
});

test('phase 4 permissions are registered without blanket role grants', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);

    foreach (PermissionCodes::customerPhase4Catalogue() as $code) {
        expect(Permission::query()->where('code', $code)->exists())->toBeTrue($code);
    }

    $admin = Role::query()->where('slug', 'customer.admin')->firstOrFail();
    $adminCodes = RolePermission::query()
        ->where('role_id', $admin->id)
        ->whereNull('deactivated_at')
        ->whereNull('deleted_at')
        ->with('permission')
        ->get()
        ->pluck('permission.code');

    foreach (PermissionCodes::customerPhase4Catalogue() as $code) {
        expect($adminCodes)->not->toContain($code);
    }

    expect($adminCodes)->not->toContain(PermissionCodes::PLATFORM_DELETE)
        ->and($adminCodes)->not->toContain(PermissionCodes::ENQUIRY_DELETE)
        ->and($adminCodes)->not->toContain(PermissionCodes::LR_DELETE)
        ->and($adminCodes)->not->toContain(PermissionCodes::LR_NUMBER_ADJUST);
});

test('webhook credentials store hashed tokens and support revoke and rotation', function () {
    $company = Company::factory()->create();
    $service = new EnquiryWebhookCredentialService;
    $created = $service->create($company, label: 'primary');

    expect($created['credential']->token_hash)->toBe($service->hashToken($created['plain_token']))
        ->and($created['credential']->token_hash)->not->toBe($created['plain_token'])
        ->and($service->findUsableByPlainToken($created['plain_token']))->not->toBeNull();

    $service->revoke($created['credential']);
    expect($service->findUsableByPlainToken($created['plain_token']))->toBeNull();

    $existing = $service->create($company, label: 'to-rotate');
    $rotated = $service->rotate($existing['credential'], revokeExistingImmediately: true);
    expect($service->findUsableByPlainToken($existing['plain_token']))->toBeNull()
        ->and($service->findUsableByPlainToken($rotated['plain_token']))->not->toBeNull()
        ->and($rotated['credential']->replaces_credential_id)->toBe($existing['credential']->id)
        ->and($service->findUsableByPlainToken(''))->toBeNull()
        ->and($service->findUsableByPlainToken('short'))->toBeNull();
});

test('external references are durable and unique per company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    EnquiryExternalReference::factory()->create([
        'company_id' => $companyA->id,
        'external_reference' => 'stable-ref-1',
    ]);

    $failed = false;
    try {
        DB::transaction(function () use ($companyA): void {
            EnquiryExternalReference::factory()->create([
                'company_id' => $companyA->id,
                'external_reference' => 'stable-ref-1',
            ]);
        });
    } catch (QueryException) {
        $failed = true;
    }
    expect($failed)->toBeTrue();

    expect(EnquiryExternalReference::factory()->create([
        'company_id' => $companyB->id,
        'external_reference' => 'stable-ref-1',
    ]))->external_reference->toBe('stable-ref-1');
});

test('document numbering allocates unique company and branch scoped numbers concurrently', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $allocator = new DocumentNumberAllocator;

    $first = $allocator->allocateNext($company->id, DocumentNumberType::Enquiry, DocumentNumberScopeType::Company);
    $second = $allocator->allocateNext($company->id, DocumentNumberType::Enquiry, DocumentNumberScopeType::Company);

    expect($first['number'])->not->toBe($second['number']);

    $sharedA = $allocator->allocateNext($company->id, DocumentNumberType::LorryReceipt, DocumentNumberScopeType::Company);
    $manualConflict = false;
    try {
        $allocator->allocateManual(
            $company->id,
            DocumentNumberType::LorryReceipt,
            DocumentNumberScopeType::Company,
            $sharedA['number'],
        );
    } catch (DocumentNumberConflictException) {
        $manualConflict = true;
    }
    expect($manualConflict)->toBeTrue();

    $branchOne = $allocator->allocateNext(
        $company->id,
        DocumentNumberType::LorryReceipt,
        DocumentNumberScopeType::Branch,
        $branch->id,
    );
    $otherBranch = Branch::factory()->create(['company_id' => $company->id]);
    $branchTwo = $allocator->allocateManual(
        $company->id,
        DocumentNumberType::LorryReceipt,
        DocumentNumberScopeType::Branch,
        $branchOne['number'],
        $otherBranch->id,
    );
    expect($branchTwo->number)->toBe($branchOne['number']);

    $actor = User::factory()->customer()->create();
    $adjustment = $allocator->adjustSequence($first['sequence'], 100, $actor, 'Reset for FY');
    expect($adjustment->new_next_value)->toBe(100)
        ->and($adjustment->remark)->toBe('Reset for FY');

    // Concurrent auto allocation race: two sequential locked transactions still unique.
    $numbers = [];
    DB::transaction(function () use ($allocator, $company, &$numbers): void {
        $numbers[] = $allocator->allocateNext($company->id, DocumentNumberType::Enquiry, DocumentNumberScopeType::Company)['number'];
    });
    DB::transaction(function () use ($allocator, $company, &$numbers): void {
        $numbers[] = $allocator->allocateNext($company->id, DocumentNumberType::Enquiry, DocumentNumberScopeType::Company)['number'];
    });
    expect($numbers[0])->not->toBe($numbers[1]);
});

test('document numbering validation errors are distinct from uniqueness conflicts', function () {
    $company = Company::factory()->create();
    $allocator = new DocumentNumberAllocator;
    $actor = User::factory()->customer()->create();

    expect(fn () => $allocator->allocateManual(
        $company->id,
        DocumentNumberType::Enquiry,
        DocumentNumberScopeType::Company,
        '   ',
    ))->toThrow(DocumentNumberValidationException::class);

    expect(fn () => $allocator->allocateNext(
        $company->id,
        DocumentNumberType::LorryReceipt,
        DocumentNumberScopeType::Branch,
        null,
    ))->toThrow(DocumentNumberValidationException::class);

    $allocated = $allocator->allocateNext($company->id, DocumentNumberType::Enquiry, DocumentNumberScopeType::Company);

    expect(fn () => $allocator->adjustSequence($allocated['sequence'], 0, $actor, 'bad'))
        ->toThrow(DocumentNumberValidationException::class);

    expect(fn () => $allocator->adjustSequence($allocated['sequence'], 5, $actor, '  '))
        ->toThrow(DocumentNumberValidationException::class);
});

test('document numbering does not disguise foreign-key failures as conflicts', function () {
    $allocator = new DocumentNumberAllocator;
    $invalidCompanyId = (string) Str::uuid();

    $caught = null;
    try {
        $allocator->allocateManual(
            $invalidCompanyId,
            DocumentNumberType::Enquiry,
            DocumentNumberScopeType::Company,
            'MANUAL-1',
        );
    } catch (DocumentNumberConflictException $e) {
        $caught = $e;
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught)->not->toBeInstanceOf(DocumentNumberConflictException::class);
});

test('composite dependency guard aggregates registered blockers and ignores empty registry', function () {
    $branch = Branch::factory()->create();
    $lr = LorryReceipt::factory()->forBranch($branch)->create();

    $empty = new CompositeLrDependencyGuard([]);
    expect($empty->blockersForCancel($lr))->toBe([])
        ->and($empty->blockersForSoftDelete($lr))->toBe([]);

    $stub = new class implements LrDependencyGuard
    {
        public function blockersForCancel(LorryReceipt $lorryReceipt): array
        {
            return [new LrDependencyBlocker('trip.active', 'Active trip', 'test-guard')];
        }

        public function blockersForSoftDelete(LorryReceipt $lorryReceipt): array
        {
            return [new LrDependencyBlocker('trip.exists', 'Trip exists', 'test-guard')];
        }
    };

    $composite = new CompositeLrDependencyGuard([$stub]);
    expect($composite->blockersForCancel($lr))->toHaveCount(1)
        ->and($composite->blockersForSoftDelete($lr)[0]->code)->toBe('trip.exists');
});
