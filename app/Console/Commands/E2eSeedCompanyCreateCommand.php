<?php

namespace App\Console\Commands;

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Identity\Enums\IdentityType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\GstRate;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deterministic non-production fixtures for company-create Playwright journeys.
 */
#[Signature('e2e:seed-company-create {--password= : Persona password (defaults to local_demo.password or \"password\")}')]
#[Description('Seed isolated Customer/Internal personas for company-create E2E (non-production only)')]
class E2eSeedCompanyCreateCommand extends Command
{
    public const OWNER_EMAIL = 'e2e.owner@truckbill.test';

    public const NO_COMPANY_EMAIL = 'e2e.nocompany@truckbill.test';

    public const INTERNAL_EMAIL = 'e2e.internal@truckbill.test';

    public const SHARED_COMPANY_NAME = 'E2E Shared Visibility Co';

    public const RESTRICTED_COMPANY_NAME = 'E2E Restricted Visibility Co';

    public function handle(CompanyWorkspaceService $workspace): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: e2e:seed-company-create must not run in production.');

            return self::FAILURE;
        }

        $password = trim((string) ($this->option('password') ?: config('local_demo.password') ?: 'password'));
        if ($password === '') {
            throw new RuntimeException('Provide --password or set LOCAL_DEMO_PASSWORD for e2e seeding.');
        }

        $rate = GstRate::query()->firstOrCreate(
            ['code' => 'GST18', 'version' => 1],
            [
                'name' => 'GST 18%',
                'rate_percent' => '18.000',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_active' => true,
            ],
        );

        $this->callSilent(SubscriptionCatalogueSeeder::class);

        $owner = $this->ensureUser(self::OWNER_EMAIL, 'E2E Owner', $password, IdentityType::Customer);
        $ownsCompany = Company::query()->where('owner_user_id', $owner->id)->exists();
        if (! $ownsCompany) {
            $workspace->createCompany($owner, 'E2E Existing Co', 'Head Office');
        }

        $shared = $this->ensureVisibilityCompany(
            $workspace,
            $owner,
            $rate->id,
            self::SHARED_COMPANY_NAME,
            DataVisibility::SharedData,
            'Shared HO',
            'Shared Depot',
            'AAAAA1111A',
        );
        $restricted = $this->ensureVisibilityCompany(
            $workspace,
            $owner,
            $rate->id,
            self::RESTRICTED_COMPANY_NAME,
            DataVisibility::BranchWiseRestricted,
            'Restricted HO',
            'Restricted Depot',
            'BBBBB2222B',
        );

        $this->writeFixtureManifest($shared, $restricted);

        $noCompany = $this->ensureUser(self::NO_COMPANY_EMAIL, 'E2E No Company', $password, IdentityType::Customer);
        $this->resetZeroCompanyPersona($noCompany);

        $this->ensureUser(self::INTERNAL_EMAIL, 'E2E Internal', $password, IdentityType::Internal);

        $this->info('E2E company-create personas ready.');
        $this->line('  owner: '.self::OWNER_EMAIL);
        $this->line('  no-company: '.self::NO_COMPANY_EMAIL);
        $this->line('  internal: '.self::INTERNAL_EMAIL);
        $this->line('  shared: '.self::SHARED_COMPANY_NAME);
        $this->line('  restricted: '.self::RESTRICTED_COMPANY_NAME);

        return self::SUCCESS;
    }

    /**
     * @return array{company: Company, branch_a: Branch, branch_b: Branch}
     */
    private function ensureVisibilityCompany(
        CompanyWorkspaceService $workspace,
        User $owner,
        string $gstRateId,
        string $name,
        DataVisibility $visibility,
        string $headOffice,
        string $secondBranch,
        string $pan,
    ): array {
        $company = Company::query()
            ->where('owner_user_id', $owner->id)
            ->where('name', $name)
            ->first();

        if ($company === null) {
            $company = $workspace->createCompany(
                $owner,
                $name,
                $headOffice,
                [
                    'pan' => $pan,
                    'registered_address' => '12 E2E Park',
                    'state' => 'Maharashtra',
                    'city' => 'Mumbai',
                    'pin' => '400001',
                    'contact_person' => $owner->name,
                    'mobile' => '9876543210',
                    'email' => $owner->email,
                    'gst_registration_type' => GstRegistrationType::Regular->value,
                    'tax_liability' => TaxLiability::Fcm->value,
                    'gstin' => '27'.$pan.'1Z5',
                    'gst_rate_id' => $gstRateId,
                    'data_visibility' => $visibility->value,
                    'timezone' => 'Asia/Kolkata',
                ],
                true,
            );
        } elseif ($company->data_visibility !== $visibility) {
            $company->forceFill(['data_visibility' => $visibility])->save();
        }

        $branchA = $company->branches()->where('name', $headOffice)->whereNull('deactivated_at')->first()
            ?? $company->branches()->whereNull('deactivated_at')->orderBy('created_at')->firstOrFail();

        if ($branchA->name !== $headOffice) {
            $branchA->forceFill(['name' => $headOffice])->save();
        }

        $branchB = $company->branches()->where('name', $secondBranch)->whereNull('deactivated_at')->first();
        if ($branchB === null) {
            $branchB = $workspace->createBranch($company, $secondBranch, 'E2E second branch');
        }

        $this->ensureActiveSubscriptionFixture($company);

        return [
            'company' => $company->refresh(),
            'branch_a' => $branchA->refresh(),
            'branch_b' => $branchB->refresh(),
        ];
    }

    /**
     * Additional owned companies do not receive first-company trial; grant an
     * active paid-through subscription so Branch Master mutations work in E2E.
     */
    private function ensureActiveSubscriptionFixture(Company $company): void
    {
        $active = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereDate('paid_through_date', '>=', now()->toDateString())
            ->exists();

        if ($active) {
            return;
        }

        $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->first();
        if ($catalogue === null) {
            throw new RuntimeException('Subscription catalogue missing for E2E active subscription fixture.');
        }

        $paidThrough = CarbonImmutable::now()->addYear()->toDateString();

        CompanySubscription::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'catalogue_version_id' => $catalogue->id,
            'status' => 'active',
            'common_renewal_date' => $paidThrough,
            'tenure' => 'yearly',
            'renewal_eligible' => true,
            'core_module_count' => 1,
            'list_annual_total' => '0.00',
            'discounted_annual_total' => '0.00',
            'pricing_breakdown' => ['e2e_fixture' => true],
            'activated_at' => now(),
            'paid_through_date' => $paidThrough,
            'grace_ends_on' => CarbonImmutable::parse($paidThrough)->addDays(7)->toDateString(),
        ]);
    }

    /**
     * @param  array{company: Company, branch_a: Branch, branch_b: Branch}  $shared
     * @param  array{company: Company, branch_a: Branch, branch_b: Branch}  $restricted
     */
    private function writeFixtureManifest(array $shared, array $restricted): void
    {
        $payload = [
            'shared' => [
                'company_id' => $shared['company']->id,
                'company_name' => $shared['company']->name,
                'branch_a_id' => $shared['branch_a']->id,
                'branch_b_id' => $shared['branch_b']->id,
            ],
            'restricted' => [
                'company_id' => $restricted['company']->id,
                'company_name' => $restricted['company']->name,
                'branch_a_id' => $restricted['branch_a']->id,
                'branch_b_id' => $restricted['branch_b']->id,
            ],
        ];

        Storage::disk('local')->put(
            'e2e/owner-global-panel.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function ensureUser(string $email, string $name, string $password, IdentityType $type): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'identity_type' => $type,
                'is_super_admin' => false,
                'deactivated_at' => null,
            ]);
            $user->save();

            return $user;
        }

        $user->forceFill([
            'name' => $name,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => Hash::make($password),
            'identity_type' => $type,
            'is_super_admin' => false,
            'deactivated_at' => null,
        ])->save();

        return $user->refresh();
    }

    /**
     * Scenario B requires a Customer with no accessible companies.
     * Owned companies remain listed even when deactivated, so reassign ownership
     * to the E2E owner persona and deactivate memberships for this user.
     */
    private function resetZeroCompanyPersona(User $user): void
    {
        $graveyardOwner = User::query()->where('email', self::OWNER_EMAIL)->first();
        if ($graveyardOwner === null) {
            return;
        }

        // Bypass Company::updating owner guard — E2E fixture hygiene only.
        DB::table('companies')
            ->where('owner_user_id', $user->id)
            ->update([
                'owner_user_id' => $graveyardOwner->id,
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('company_memberships')
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->update([
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
