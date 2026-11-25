<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Models\BankCashAccount;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    TenantContextHolder::clear();
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function (): void {
    TenantContextHolder::clear();
});

it('creates bank account via json quick-create', function (): void {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Bank Cash QA Co');
    $branch = $company->branches()->firstOrFail();

    $this->flushSession();
    $this->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $response = $this->postJson(route('branch.bank-cash.store'), [
        'account_kind' => 'bank',
        'name' => 'HDFC Ops',
        'bank_name' => 'HDFC Bank',
        'account_number' => '50100123456789',
        'remark' => 'Quick create',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'HDFC Ops')
        ->assertJsonPath('data.account_kind', 'bank');

    $account = BankCashAccount::query()->findOrFail($response->json('data.id'));
    expect($account->name)->toBe('HDFC Ops')
        ->and($account->account_kind)->toBe('bank')
        ->and($account->company_id)->toBe($company->id);
});

it('creates cash account via json quick-create', function (): void {
    $owner = User::factory()->customer()->create([
        'password' => Hash::make('owner-secret'),
    ]);
    $company = app(CompanyWorkspaceService::class)->createCompany($owner, 'Cash QA Co');
    $branch = $company->branches()->firstOrFail();

    $this->flushSession();
    $this->actingAs($owner);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($company, $branch);

    $response = $this->postJson(route('branch.bank-cash.store'), [
        'account_kind' => 'cash',
        'name' => 'Petty Cash HO',
        'remark' => 'Quick create cash',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Petty Cash HO')
        ->assertJsonPath('data.account_kind', 'cash');
});
