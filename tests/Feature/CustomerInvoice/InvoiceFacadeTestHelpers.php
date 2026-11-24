<?php

use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Invoice\Enums\BrokerInvoicePaymentStatus;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceParticularsMode;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoicePaymentStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceType;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\RoleAssignmentService;
use App\Models\BrokerInvoice;
use App\Models\Role;
use App\Models\TripInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function invoiceFacadeCustomerRole(string $slug): Role
{
    return Role::query()
        ->where('domain', PermissionDomain::Customer->value)
        ->where('slug', $slug)
        ->firstOrFail();
}

function invoiceFacadeWorkspace(): array
{
    $workspace = app(CompanyWorkspaceService::class);
    $rbac = app(RoleAssignmentService::class);

    $owner = User::factory()->customer()->create(['password' => Hash::make('owner-secret')]);
    $company = $workspace->createCompany($owner, 'Invoice Facade Co');
    $branch = $company->branches()->firstOrFail();

    $member = User::factory()->customer()->create(['password' => Hash::make('member-secret')]);
    $membership = $workspace->addMembership($company, $member, 'Join');
    $assignment = $workspace->assignBranch($membership, $branch, 'Assign');
    $rbac->assignCustomerBranchRole(
        $owner,
        $membership,
        $branch,
        $assignment,
        invoiceFacadeCustomerRole('customer.admin'),
        'Grant admin',
    );

    return compact('owner', 'company', 'branch', 'member');
}

function invoiceFacadeLogin(User $user, string $password): void
{
    if (auth()->check()) {
        test()->post(route('logout'));
    }

    test()->flushSession();
    $response = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertRedirect();
    foreach ($response->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }
}

function seedLeanOwnFleetTripInvoices(
    string $companyId,
    string $branchId,
    string $customerPartyId,
    int $createdByUserId,
    int $count,
    string $numberPrefix = 'UF-INV-',
): void {
    $baseDate = now();
    $payload = [];

    foreach (range(1, $count) as $index) {
        $payload[] = [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'invoice_number' => $numberPrefix.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
            'billing_customer_party_id' => $customerPartyId,
            'invoice_type' => TripInvoiceType::Individual->value,
            'particulars_mode' => TripInvoiceParticularsMode::SeparateLines->value,
            'status' => TripInvoiceStatus::Pending->value,
            'payment_status' => TripInvoicePaymentStatus::Pending->value,
            'invoice_date' => $baseDate->copy()->subDays($count - $index)->toDateString(),
            'base_freight_total' => '1000.00',
            'additional_charges_total' => '0.00',
            'taxable_total' => '1000.00',
            'gst_amount' => '0.00',
            'invoice_total' => '1000.00',
            'amount_received_total' => '0.00',
            'tds_received_total' => '0.00',
            'deductions_total' => '0.00',
            'settlement_total' => '0.00',
            'balance_due' => '1000.00',
            'invoice_tds_rate_percent' => '0.00',
            'tds_basis_freight' => '1000.00',
            'billing_customer_snapshot' => json_encode(['name' => 'Customer'], JSON_THROW_ON_ERROR),
            'created_by_user_id' => $createdByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($payload, 100) as $chunk) {
        TripInvoice::query()->insert($chunk);
    }
}

function seedLeanBrokerInvoices(
    string $companyId,
    string $branchId,
    string $customerPartyId,
    int $createdByUserId,
    int $count,
    string $numberPrefix = 'BR-INV-',
): void {
    $baseDate = now();
    $payload = [];

    foreach (range(1, $count) as $index) {
        $payload[] = [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'invoice_number' => $numberPrefix.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
            'billing_customer_party_id' => $customerPartyId,
            'status' => BrokerInvoiceStatus::Issued->value,
            'payment_status' => BrokerInvoicePaymentStatus::Unpaid->value,
            'invoice_date' => $baseDate->copy()->subDays($count - $index)->toDateString(),
            'taxable_total' => '800.00',
            'gst_amount' => '0.00',
            'invoice_total' => '800.00',
            'amount_received' => '0.00',
            'customer_tds_amount' => '0.00',
            'balance_due' => '800.00',
            'created_by_user_id' => $createdByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($payload, 100) as $chunk) {
        BrokerInvoice::query()->insert($chunk);
    }
}

function seedLeanOwnFleetTripInvoice(
    string $companyId,
    string $branchId,
    string $customerPartyId,
    int $createdByUserId,
    string $invoiceNumber,
): void {
    TripInvoice::query()->create([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'invoice_number' => $invoiceNumber,
        'billing_customer_party_id' => $customerPartyId,
        'invoice_type' => TripInvoiceType::Individual->value,
        'particulars_mode' => TripInvoiceParticularsMode::SeparateLines->value,
        'status' => TripInvoiceStatus::Pending->value,
        'payment_status' => TripInvoicePaymentStatus::Pending->value,
        'invoice_date' => now()->toDateString(),
        'base_freight_total' => '1000.00',
        'additional_charges_total' => '0.00',
        'taxable_total' => '1000.00',
        'gst_amount' => '0.00',
        'invoice_total' => '1000.00',
        'amount_received_total' => '0.00',
        'tds_received_total' => '0.00',
        'deductions_total' => '0.00',
        'settlement_total' => '0.00',
        'balance_due' => '1000.00',
        'invoice_tds_rate_percent' => '0.00',
        'tds_basis_freight' => '1000.00',
        'billing_customer_snapshot' => ['name' => 'Customer'],
        'created_by_user_id' => $createdByUserId,
    ]);
}
