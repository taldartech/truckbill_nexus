<?php

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\TransporterDeclarationAnnualResetService;
use App\Domains\Tax\TransporterTdsCalculationService;
use App\Models\PartyRole;
use App\Models\Role;
use App\Models\TdsRule;
use App\Models\TransporterDeclarationReset;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    TenantContextHolder::clear();
    (new RbacSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
    CarbonImmutable::setTestNow();
});

function transporterTdsWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'TDS Transporter Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $memberAssignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $memberAssignment,
        Role::query()
            ->where('domain', PermissionDomain::Customer->value)
            ->where('slug', 'customer.admin')
            ->firstOrFail(),
        'Grant admin',
    );

    return compact('owner', 'company', 'branch', 'member');
}

function calculateTransporterTds(array $roleAttrs, array $partyAttrs = [], ?string $overrideRate = null, ?string $overrideRemark = null, bool $allowOverride = false)
{
    $service = app(TransporterTdsCalculationService::class);
    $context = $service->buildContextFromTransporter($partyAttrs, $roleAttrs);

    return $service->calculate($context, $overrideRate, $overrideRemark, $allowOverride);
}

test('individual PAN P or H resolves to 1 percent', function (string $fourth) {
    $pan = 'ABC'.$fourth.'E1234F';
    $result = calculateTransporterTds([
        'pan' => $pan,
        'pan_status' => 'active',
        'declaration' => false,
    ]);

    expect($result->applicableRate)->toBe('1.000')
        ->and($result->calculatedRate)->toBe('1.000')
        ->and($result->ruleCode)->toBe('PAN_INDIVIDUAL_1')
        ->and($result->overrideState)->toBeFalse();
})->with(['P', 'H']);

test('entity PAN C resolves to 2 percent via reference seeder', function () {
    $result = calculateTransporterTds([
        'pan' => 'ABCCE1234F',
        'pan_status' => 'active',
        'declaration' => false,
    ]);

    expect($result->applicableRate)->toBe('2.000')
        ->and($result->ruleCode)->toBe('PAN_ENTITY_2');
});

test('inactive PAN resolves to 20 percent even with a declaration', function () {
    $result = calculateTransporterTds([
        'pan' => 'ABCPE1234F',
        'pan_status' => 'inactive',
        'declaration' => true,
        'goods_vehicles_count' => 2,
    ]);

    expect($result->applicableRate)->toBe('20.000')
        ->and($result->ruleCode)->toBe('INVALID_PAN');
});

test('unknown PAN status is never treated as active', function (string $status) {
    $context = app(TransporterTdsCalculationService::class)->buildContextFromTransporter(
        ['pan' => 'ABCPE1234F'],
        ['pan_status' => $status, 'declaration' => false],
    );

    expect($context['pan_status'])->toBeNull();
})->with(['invalid', 'valid', 'unknown']);

test('TDS declaration received with at most 10 vehicles resolves to 0 percent', function () {
    $result = calculateTransporterTds([
        'pan' => 'ABCPE1234F',
        'pan_status' => 'active',
        'declaration' => true,
        'goods_vehicles_count' => 10,
    ]);

    expect($result->applicableRate)->toBe('0.000')
        ->and($result->ruleCode)->toBe('OWNER_DECLARATION_ZERO')
        ->and($result->declarationStatus)->toBe('valid')
        ->and($result->goodsVehicleCount)->toBe(10);
});

test('TDS declaration received with more than 10 vehicles falls back to standard individual rate', function () {
    $result = calculateTransporterTds([
        'pan' => 'ABCPE1234F',
        'pan_status' => 'active',
        'declaration' => true,
        'goods_vehicles_count' => 11,
    ]);

    expect($result->applicableRate)->toBe('1.000')
        ->and($result->ruleCode)->toBe('PAN_INDIVIDUAL_1');
});

test('TDS declaration not received ignores the zero rule', function () {
    $result = calculateTransporterTds([
        'pan' => 'ABCPE1234F',
        'pan_status' => 'active',
        'declaration' => false,
        'goods_vehicles_count' => 3,
    ]);

    expect($result->applicableRate)->toBe('1.000')
        ->and($result->ruleCode)->toBe('PAN_INDIVIDUAL_1');
});

test('override requires remark and allowOverride', function () {
    $attrs = [
        'pan' => 'ABCPE1234F',
        'pan_status' => 'active',
        'declaration' => false,
    ];

    expect(fn () => calculateTransporterTds($attrs, [], '5.000', null, true))
        ->toThrow(ValidationException::class);

    expect(fn () => calculateTransporterTds($attrs, [], '5.000', 'Manual adjust', false))
        ->toThrow(TaxDomainException::class);

    $result = calculateTransporterTds($attrs, [], '5.000', 'Manual adjust', true);

    expect($result->overrideState)->toBeTrue()
        ->and($result->calculatedRate)->toBe('1.000')
        ->and($result->applicableRate)->toBe('5.000')
        ->and($result->ruleCode)->toBe('PAN_INDIVIDUAL_1');
});

test('no matching rule throws configuration domain exception', function () {
    expect(fn () => calculateTransporterTds([
        'pan' => 'ABCXE1234F',
        'pan_status' => 'active',
        'declaration' => false,
    ]))->toThrow(TaxDomainException::class);
});

test('buildContextFromTransporter normalizes pan and maps declaration fields', function () {
    $context = app(TransporterTdsCalculationService::class)->buildContextFromTransporter(
        ['pan' => 'abcpe1234f'],
        [
            'declaration' => true,
            'goods_vehicles_count' => '8',
            'pan_status' => 'Active',
        ],
    );

    expect($context)->toMatchArray([
        'pan_fourth_char' => 'P',
        'pan_status' => 'active',
        'declaration_valid' => true,
        'goods_vehicle_count' => 8,
    ])->not->toHaveKey('declaration_type');
});

test('party create stores calculated and applicable tds rates for transporter', function () {
    ['owner' => $owner, 'company' => $company] = transporterTdsWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Rate Store Haulage',
            'contact_number' => '9876500001',
            'pan' => 'ABCPE1234F',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'ABCPE1234F',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 4,
            ],
        ]],
    );

    $attrs = $party->roles->firstWhere('role', 'transporter')?->role_attributes;

    expect($attrs['calculated_tds_rate'] ?? null)->toBe('0.000')
        ->and($attrs['applicable_tds_rate'] ?? null)->toBe('0.000');
});

test('tds preview endpoint returns calculated rates for authorized branch user', function () {
    ['owner' => $owner, 'company' => $company, 'branch' => $branch] = transporterTdsWorkspace();
    $this->actingAs($owner);
    seedBranchSession($company, $branch);

    $this->postJson(route('branch.parties.tds-preview'), [
        'pan' => 'ABCCE1234F',
        'pan_status' => 'active',
        'declaration' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.applicable_rate', '2.000')
        ->assertJsonPath('data.rule_code', 'PAN_ENTITY_2')
        ->assertJsonPath('data.override_state', false);
});

test('april 1 declaration reset is idempotent per financial year', function () {
    ['owner' => $owner, 'company' => $company] = transporterTdsWorkspace();
    $this->actingAs($owner);

    $party = app(PartyService::class)->create(
        actor: $owner,
        company: $company,
        branch: null,
        attributes: [
            'name' => 'Reset Haulage',
            'contact_number' => '9876500002',
            'pan' => 'ABCPE1234F',
            'state' => 'MH',
            'city' => 'Pune',
        ],
        roles: [[
            'role' => PartyRoleType::Transporter->value,
            'attributes' => [
                'pan' => 'ABCPE1234F',
                'pan_status' => 'active',
                'declaration' => true,
                'goods_vehicles_count' => 4,
            ],
        ]],
    );

    $role = $party->roles->firstWhere('role', 'transporter');
    expect($role?->role_attributes['declaration'] ?? null)->toBeTrue()
        ->and($role?->role_attributes['applicable_tds_rate'] ?? null)->toBe('0.000');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 00:10:00', 'Asia/Kolkata'));
    $service = app(TransporterDeclarationAnnualResetService::class);

    $first = $service->resetForCurrentFinancialYear();
    expect($first['reset'])->toBeTrue()
        ->and($first['financial_year'])->toBe(2026)
        ->and($first['transporters_updated'])->toBeGreaterThanOrEqual(1);

    $role = PartyRole::query()->findOrFail($role->id);
    expect($role->role_attributes['declaration'] ?? null)->toBeFalse()
        ->and($role->role_attributes['applicable_tds_rate'] ?? null)->toBe('1.000');

    $second = $service->resetForCurrentFinancialYear();
    expect($second['reset'])->toBeFalse()
        ->and($second['marker_id'])->toBe($first['marker_id'])
        ->and(TransporterDeclarationReset::query()->where('financial_year', 2026)->count())->toBe(1);

    $this->artisan('tax:reset-transporter-declarations')
        ->assertSuccessful();

    expect(TransporterDeclarationReset::query()->where('financial_year', 2026)->count())->toBe(1);
});

test('reference rules seeder is idempotent', function () {
    (new TdsReferenceRulesSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();

    expect(TdsRule::query()->whereIn('code', [
        'INVALID_PAN',
        'OWNER_DECLARATION_ZERO',
        'PAN_INDIVIDUAL_1',
        'PAN_ENTITY_2',
    ])->count())->toBe(4);
});
