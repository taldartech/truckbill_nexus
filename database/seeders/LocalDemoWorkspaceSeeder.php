<?php

namespace Database\Seeders;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscription;
use App\Models\Enquiry;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Station;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opt-in local/testing demo workspace (companies, roles, masters, enquiries).
 *
 * Never called by DatabaseSeeder. Refuse production.
 *
 *   LOCAL_DEMO_PASSWORD=... php artisan db:seed --class=LocalDemoWorkspaceSeeder
 *   LOCAL_DEMO_PROFILE=small|standard|large (default: standard)
 *
 * Passwords come only from LOCAL_DEMO_PASSWORD (required). Never logged.
 */
class LocalDemoWorkspaceSeeder extends Seeder
{
    public const FIXTURE_NAMESPACE = 'truckbill.local-demo';

    public const COMPANY_A_NAME = 'Demo Logistics Alpha';

    public const COMPANY_B_NAME = 'Demo Freight Beta';

    /** Single-branch (HO only) company owned by Owner A — multi-company Owner fixture. */
    public const COMPANY_C_NAME = 'Demo Solo Gamma';

    /** Deactivated company owned by Owner A — limited Owner Panel fixture. */
    public const COMPANY_D_NAME = 'Demo Suspended Delta';

    public const OWNER_A_EMAIL = 'owner.alpha@demo.test';

    public const OWNER_B_EMAIL = 'owner.beta@demo.test';

    public const MANAGER_A_EMAIL = 'manager.alpha@demo.test';

    public const OPERATOR_A_EMAIL = 'operator.alpha@demo.test';

    public const VIEWER_A_EMAIL = 'viewer.alpha@demo.test';

    public const RESTRICTED_A_EMAIL = 'restricted.alpha@demo.test';

    public const NO_ACCESS_EMAIL = 'noaccess.demo@demo.test';

    public const ROLE_MANAGER = 'demo.enquiry_manager';

    public const ROLE_OPERATOR = 'demo.enquiry_operator';

    public const ROLE_VIEWER = 'demo.enquiry_viewer';

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            $this->command?->error('LocalDemoWorkspaceSeeder is blocked outside local/testing.');

            throw new RuntimeException('LocalDemoWorkspaceSeeder refused: environment is not local/testing.');
        }

        $password = trim((string) config('local_demo.password', ''));
        if ($password === '') {
            throw new RuntimeException(
                'Set LOCAL_DEMO_PASSWORD before seeding the local demo workspace (value is never committed).'
            );
        }

        $profile = strtolower((string) config('local_demo.profile', 'standard'));
        if (! in_array($profile, ['small', 'standard', 'large'], true)) {
            throw new RuntimeException('LOCAL_DEMO_PROFILE must be small, standard, or large.');
        }

        $this->call(RbacSeeder::class);
        $this->call(SubscriptionCatalogueSeeder::class);

        $this->ensureDemoRoles();

        $workspace = app(CompanyWorkspaceService::class);
        $access = app(CompanyAccessService::class);
        $rbac = app(RoleAssignmentService::class);
        $parties = app(PartyService::class);
        $enquiries = app(EnquiryService::class);
        $contracts = app(CompanySubscriptionContractService::class);

        $alpha = $this->seedCompanyWorkspace(
            workspace: $workspace,
            access: $access,
            rbac: $rbac,
            parties: $parties,
            enquiries: $enquiries,
            contracts: $contracts,
            password: $password,
            profile: $profile,
            ownerEmail: self::OWNER_A_EMAIL,
            ownerName: 'Demo Alpha Owner',
            companyName: self::COMPANY_A_NAME,
            headOfficeName: 'Mumbai HO',
            secondBranchName: 'Pune Branch',
            withFullStaff: true,
        );

        $this->seedCompanyWorkspace(
            workspace: $workspace,
            access: $access,
            rbac: $rbac,
            parties: $parties,
            enquiries: $enquiries,
            contracts: $contracts,
            password: $password,
            profile: $profile,
            ownerEmail: self::OWNER_B_EMAIL,
            ownerName: 'Demo Beta Owner',
            companyName: self::COMPANY_B_NAME,
            headOfficeName: 'Ahmedabad HO',
            secondBranchName: 'Surat Branch',
            withFullStaff: false,
        );

        // Owner A second company: HO only (no second branch) for multi-company switcher + capacity demos.
        $this->seedMinimalOwnedCompany(
            workspace: $workspace,
            ownerEmail: self::OWNER_A_EMAIL,
            companyName: self::COMPANY_C_NAME,
            headOfficeName: 'Gamma HO',
            createSecondBranch: false,
        );

        // Owner A deactivated company for limited Owner Panel (admin_billing_only) demos.
        $suspended = $this->seedMinimalOwnedCompany(
            workspace: $workspace,
            ownerEmail: self::OWNER_A_EMAIL,
            companyName: self::COMPANY_D_NAME,
            headOfficeName: 'Delta HO',
            createSecondBranch: false,
        );
        if ($suspended->deactivated_at === null) {
            $sa = $this->ensureSuperAdminUser('sa.demo.lifecycle@demo.test', 'Demo Lifecycle SA', $password);
            $workspace->deactivateCompany($sa, $suspended, 'Local demo: suspended company fixture');
        }

        $this->ensureCustomerUser(self::NO_ACCESS_EMAIL, 'Demo No Access', $password);

        Auth::logout();

        $this->command?->info('Local demo workspace ready (profile='.$profile.').');
        $this->command?->info('Company A: '.self::COMPANY_A_NAME.' ('.$alpha['company']->id.')');
        $this->command?->info('Personas use @demo.test emails — set LOCAL_DEMO_PASSWORD to authenticate.');
        $this->command?->warn('Not invoked by DatabaseSeeder. Never run in production.');
    }

    /**
     * @return array{owner: User, company: Company, head: Branch, second: Branch}
     */
    private function seedCompanyWorkspace(
        CompanyWorkspaceService $workspace,
        CompanyAccessService $access,
        RoleAssignmentService $rbac,
        PartyService $parties,
        EnquiryService $enquiries,
        CompanySubscriptionContractService $contracts,
        string $password,
        string $profile,
        string $ownerEmail,
        string $ownerName,
        string $companyName,
        string $headOfficeName,
        string $secondBranchName,
        bool $withFullStaff,
    ): array {
        $owner = $this->ensureCustomerUser($ownerEmail, $ownerName, $password);

        $company = Company::query()
            ->where('owner_user_id', $owner->id)
            ->where('name', $companyName)
            ->first();

        if ($company === null) {
            $company = $workspace->createCompany($owner, $companyName, $headOfficeName);
        }

        $head = $company->branches()->where('name', $headOfficeName)->whereNull('deactivated_at')->first()
            ?? $company->branches()->whereNull('deactivated_at')->orderBy('created_at')->firstOrFail();

        if ($head->name !== $headOfficeName) {
            $head->forceFill(['name' => $headOfficeName])->save();
        }

        $second = $company->branches()->where('name', $secondBranchName)->whereNull('deactivated_at')->first();
        if ($second === null) {
            $second = $workspace->createBranch($company, $secondBranchName, 'Local demo: create second branch');
        }

        $ownerMembership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $owner->id)
            ->whereNull('deactivated_at')
            ->firstOrFail();

        $ownerHeadAssignment = $this->ensureBranchAssignment($workspace, $ownerMembership, $head);
        $this->ensureBranchRole($rbac, $owner, $ownerMembership, $head, $ownerHeadAssignment, self::ROLE_MANAGER);

        $ownerSecondAssignment = $this->ensureBranchAssignment($workspace, $ownerMembership, $second);
        $this->ensureBranchRole($rbac, $owner, $ownerMembership, $second, $ownerSecondAssignment, self::ROLE_MANAGER);

        if ($withFullStaff) {
            $this->seedStaffMember(
                $workspace,
                $rbac,
                $owner,
                $company,
                $head,
                self::MANAGER_A_EMAIL,
                'Demo Alpha Manager',
                $password,
                self::ROLE_MANAGER,
            );
            $this->seedStaffMember(
                $workspace,
                $rbac,
                $owner,
                $company,
                $head,
                self::OPERATOR_A_EMAIL,
                'Demo Alpha Operator',
                $password,
                self::ROLE_OPERATOR,
            );
            $this->seedStaffMember(
                $workspace,
                $rbac,
                $owner,
                $company,
                $second,
                self::VIEWER_A_EMAIL,
                'Demo Alpha Viewer',
                $password,
                self::ROLE_VIEWER,
            );
            $this->seedStaffMember(
                $workspace,
                $rbac,
                $owner,
                $company,
                $second,
                self::RESTRICTED_A_EMAIL,
                'Demo Alpha Restricted',
                $password,
                'customer.viewer_no_freight',
            );
        }

        Auth::login($owner);

        $catalogues = $this->ensureCatalogues($owner);
        $customerParty = $this->ensureParty(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':customer-1',
            $companyName === self::COMPANY_A_NAME ? 'Alpha Retail Customer' : 'Beta Industrial Customer',
            [PartyRoleType::Customer->value],
            $companyName === self::COMPANY_A_NAME ? 'Mumbai' : 'Ahmedabad',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $head,
            'demo-party:'.$company->id.':transporter-1',
            $companyName === self::COMPANY_A_NAME ? 'Konkan Transporters' : 'Saurashtra Carriers',
            [
                [
                    'role' => PartyRoleType::Transporter->value,
                    'attributes' => [
                        'pan' => 'ABCDE'.substr((string) abs(crc32($company->id)), 0, 4).'F',
                        'pan_status' => 'verified_demo',
                        'declaration' => 'Local demo transporter declaration only.',
                    ],
                ],
            ],
            $companyName === self::COMPANY_A_NAME ? 'Thane' : 'Rajkot',
        );

        $partyCount = match ($profile) {
            'small' => 2,
            'large' => 28,
            default => 8,
        };

        for ($i = 2; $i <= $partyCount; $i++) {
            $this->ensureParty(
                $parties,
                $owner,
                $company,
                $i % 2 === 0 ? $head : $second,
                'demo-party:'.$company->id.':extra-'.$i,
                'Demo Party '.$i.' '.$company->name,
                [PartyRoleType::Consignor->value, PartyRoleType::Consignee->value],
                $i % 2 === 0 ? 'Nashik' : 'Kolhapur',
            );
        }

        $this->ensureVehicle($company, $head, 'MH12DEMO'.substr((string) crc32($company->id.'1'), 0, 4));
        $this->ensureVehicle($company, $second, 'MH14DEMO'.substr((string) crc32($company->id.'2'), 0, 4));
        $this->ensureBank($company, $head, 'demo-bank:'.$company->id.':1');
        $this->ensureStation($owner, 'demo-station:'.$company->id.':1', $companyName === self::COMPANY_A_NAME ? 'Bhiwandi Hub' : 'Vatva Hub');

        $this->ensureDraftSubscription($contracts, $owner, $company);

        $manualCount = match ($profile) {
            'small' => 1,
            'large' => 12,
            default => 3,
        };

        for ($i = 1; $i <= $manualCount; $i++) {
            $branch = $i % 2 === 0 ? $second : $head;
            $access->activateContext($owner, $company, $branch);
            $this->ensureManualEnquiry(
                $enquiries,
                $company,
                $branch,
                $owner,
                $customerParty,
                $catalogues,
                'demo-manual:'.$company->id.':'.$branch->id.':'.$i,
                $i,
            );
        }

        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: null,
            ownerId: (string) $company->owner_user_id,
        ));
        $this->ensureWebsiteEnquiry(
            $enquiries,
            $company,
            $catalogues,
            'demo-web-'.$this->slugCompany($companyName).'-001',
        );
        TenantContextHolder::clear();

        Auth::logout();

        return [
            'owner' => $owner,
            'company' => $company,
            'head' => $head,
            'second' => $second,
        ];
    }

    private function ensureDemoRoles(): void
    {
        $definitions = [
            self::ROLE_MANAGER => [
                'name' => 'Demo Enquiry Manager',
                'codes' => [
                    PermissionCodes::PLATFORM_VIEW,
                    PermissionCodes::PLATFORM_CREATE,
                    PermissionCodes::PLATFORM_EDIT,
                    PermissionCodes::PLATFORM_PRINT,
                    PermissionCodes::PLATFORM_VIEW_FREIGHT,
                    PermissionCodes::ENQUIRY_VIEW,
                    PermissionCodes::ENQUIRY_CREATE,
                    PermissionCodes::ENQUIRY_EDIT,
                    PermissionCodes::ENQUIRY_ASSIGN,
                ],
            ],
            self::ROLE_OPERATOR => [
                'name' => 'Demo Enquiry Operator',
                'codes' => [
                    PermissionCodes::PLATFORM_VIEW,
                    PermissionCodes::PLATFORM_CREATE,
                    PermissionCodes::PLATFORM_PRINT,
                    PermissionCodes::PLATFORM_VIEW_FREIGHT,
                    PermissionCodes::ENQUIRY_VIEW,
                    PermissionCodes::ENQUIRY_CREATE,
                ],
            ],
            self::ROLE_VIEWER => [
                'name' => 'Demo Enquiry Viewer',
                'codes' => [
                    PermissionCodes::PLATFORM_VIEW,
                    PermissionCodes::PLATFORM_PRINT,
                    PermissionCodes::PLATFORM_VIEW_FREIGHT,
                    PermissionCodes::ENQUIRY_VIEW,
                ],
            ],
        ];

        foreach ($definitions as $slug => $definition) {
            $role = Role::query()->firstOrCreate(
                [
                    'domain' => PermissionDomain::Customer->value,
                    'slug' => $slug,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'name' => $definition['name'],
                    'description' => 'Local demo role only ('.self::FIXTURE_NAMESPACE.'). Not a production default.',
                    'is_default' => false,
                    'is_system' => false,
                    'deactivated_at' => null,
                ],
            );

            foreach ($definition['codes'] as $code) {
                $permission = Permission::query()->where('code', $code)->firstOrFail();
                RolePermission::query()->firstOrCreate(
                    [
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'deactivated_at' => null,
                    ],
                );
            }
        }
    }

    private function seedMinimalOwnedCompany(
        CompanyWorkspaceService $workspace,
        string $ownerEmail,
        string $companyName,
        string $headOfficeName,
        bool $createSecondBranch,
        ?string $secondBranchName = null,
    ): Company {
        $owner = User::query()->where('email', $ownerEmail)->firstOrFail();

        $company = Company::query()
            ->where('owner_user_id', $owner->id)
            ->where('name', $companyName)
            ->first();

        if ($company === null) {
            $company = $workspace->createCompany($owner, $companyName, $headOfficeName);
        }

        $head = $company->branches()->where('name', $headOfficeName)->whereNull('deactivated_at')->first()
            ?? $company->branches()->whereNull('deactivated_at')->orderBy('created_at')->firstOrFail();

        if ($head->name !== $headOfficeName) {
            $head->forceFill(['name' => $headOfficeName])->save();
        }

        if ($createSecondBranch && $secondBranchName !== null) {
            $second = $company->branches()->where('name', $secondBranchName)->whereNull('deactivated_at')->first();
            if ($second === null) {
                $workspace->createBranch($company, $secondBranchName, 'Local demo: create second branch');
            }
        }

        return $company->refresh();
    }

    private function ensureSuperAdminUser(string $email, string $name, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return User::factory()->superAdmin()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
        }

        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'is_super_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'deactivated_at' => null,
        ])->save();

        return $user->refresh();
    }

    private function ensureCustomerUser(string $email, string $name, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return User::factory()->customer()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
        }

        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'deactivated_at' => null,
        ])->save();

        return $user->refresh();
    }

    private function seedStaffMember(
        CompanyWorkspaceService $workspace,
        RoleAssignmentService $rbac,
        User $owner,
        Company $company,
        Branch $branch,
        string $email,
        string $name,
        string $password,
        string $roleSlug,
    ): void {
        $user = $this->ensureCustomerUser($email, $name, $password);
        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->first();

        if ($membership === null) {
            $membership = $workspace->addMembership($company, $user, 'Local demo: add membership');
        }

        $assignment = $this->ensureBranchAssignment($workspace, $membership, $branch);
        $this->ensureBranchRole($rbac, $owner, $membership, $branch, $assignment, $roleSlug);
    }

    private function ensureBranchAssignment(
        CompanyWorkspaceService $workspace,
        CompanyMembership $membership,
        Branch $branch,
    ): BranchAssignment {
        $existing = BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $workspace->assignBranch($membership, $branch, 'Local demo: branch assignment');
    }

    private function ensureBranchRole(
        RoleAssignmentService $rbac,
        User $actor,
        CompanyMembership $membership,
        Branch $branch,
        BranchAssignment $assignment,
        string $roleSlug,
    ): void {
        $exists = BranchRoleAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return;
        }

        $role = Role::query()
            ->where('domain', PermissionDomain::Customer->value)
            ->where('slug', $roleSlug)
            ->firstOrFail();

        $rbac->assignCustomerBranchRole(
            $actor,
            $membership,
            $branch,
            $assignment,
            $role,
            'Local demo: assign '.$roleSlug,
        );
    }

    /**
     * @return array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}
     */
    private function ensureCatalogues(User $owner): array
    {
        $truck = $this->ensureCatalogue($owner, OwnerCatalogueType::TruckType, 'TATA407', 'Tata 407');
        $goods = $this->ensureCatalogue($owner, OwnerCatalogueType::GoodsType, 'GEN', 'General Cargo');
        $package = $this->ensureCatalogue($owner, OwnerCatalogueType::PackageType, 'BAG', 'Bag');
        $this->ensureCatalogue($owner, OwnerCatalogueType::DocumentType, 'PAN', 'PAN Card');
        $this->ensureCatalogue($owner, OwnerCatalogueType::ExpenseCategory, 'FUEL', 'Fuel');
        $this->ensureCatalogue($owner, OwnerCatalogueType::TyreLayout, '6W', '6-Wheel Layout');

        return ['truck' => $truck, 'goods' => $goods, 'package' => $package];
    }

    private function ensureCatalogue(
        User $owner,
        OwnerCatalogueType $type,
        string $code,
        string $name,
    ): OwnerCatalogueItem {
        return OwnerCatalogueItem::query()->firstOrCreate(
            [
                'owner_user_id' => $owner->id,
                'catalogue_type' => $type->value,
                'code' => $code,
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'attributes' => [],
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function ensureParty(
        PartyService $parties,
        User $owner,
        Company $company,
        ?Branch $branch,
        string $fixtureKey,
        string $name,
        array $roleNames,
        string $city,
    ): Party {
        $rolePayload = [];
        foreach ($roleNames as $roleName) {
            $rolePayload[] = ['role' => $roleName, 'attributes' => []];
        }

        return $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $branch,
            $fixtureKey,
            $name,
            $rolePayload,
            $city,
        );
    }

    /**
     * @param  list<array{role: string, attributes?: array<string, mixed>}>  $rolePayload
     */
    private function ensurePartyWithRoles(
        PartyService $parties,
        User $owner,
        Company $company,
        ?Branch $branch,
        string $fixtureKey,
        string $name,
        array $rolePayload,
        string $city,
    ): Party {
        $email = strtolower(str_replace([':', '.'], ['-', '-'], $fixtureKey)).'@party.demo.test';

        $existing = Party::query()
            ->where('company_id', $company->id)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $parties->create(
            actor: $owner,
            company: $company,
            branch: $branch,
            attributes: [
                'name' => $name,
                'contact_person' => 'Demo Contact',
                'contact_number' => '98'.substr((string) abs(crc32($fixtureKey)), 0, 8),
                'email' => $email,
                'state' => 'Maharashtra',
                'city' => $city,
                'address' => 'Demo address for '.$fixtureKey,
                'is_gst_applicable' => false,
            ],
            roles: $rolePayload,
            remark: 'Local demo: create party '.$fixtureKey,
        );
    }

    private function ensureVehicle(Company $company, Branch $branch, string $registration): Vehicle
    {
        $existing = Vehicle::query()
            ->where('company_id', $company->id)
            ->where('registration_number', $registration)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Vehicle::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'registration_number' => $registration,
            'vehicle_type' => 'truck',
            'make' => 'Tata',
            'model' => '407',
            'manufacture_year' => 2021,
            'is_active' => true,
        ]);
    }

    private function ensureBank(Company $company, Branch $branch, string $fixtureKey): BankCashAccount
    {
        $name = 'Demo Operating Account '.$fixtureKey;

        $existing = BankCashAccount::query()
            ->where('company_id', $company->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return BankCashAccount::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'account_kind' => 'bank',
            'name' => $name,
            'bank_name' => 'Demo Bank of India',
            'account_number' => 'DEMO'.substr((string) crc32($fixtureKey), 0, 10),
            'ifsc' => 'DEMO0001234',
            'account_holder_name' => 'Demo Account Holder',
            'is_active' => true,
        ]);
    }

    private function ensureStation(User $owner, string $fixtureKey, string $name): Station
    {
        $code = strtoupper(substr(md5($fixtureKey), 0, 6));

        $existing = Station::query()
            ->where('owner_user_id', $owner->id)
            ->where('code', $code)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Station::query()->create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'name' => $name,
            'code' => $code,
            'state' => 'Maharashtra',
            'city' => 'Demo City',
            'is_active' => true,
        ]);
    }

    private function ensureDraftSubscription(
        CompanySubscriptionContractService $contracts,
        User $owner,
        Company $company,
    ): void {
        $hasDraft = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'draft')
            ->exists();

        if ($hasDraft) {
            return;
        }

        $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->first();
        if ($catalogue === null) {
            return;
        }

        $plans = SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->get()
            ->unique('module')
            ->take(2)
            ->values();

        if ($plans->count() < 1) {
            return;
        }

        $planIds = [];
        foreach ($plans as $plan) {
            $planIds[] = (string) $plan->id;
        }

        $contracts->createDraftContract(
            actor: $owner,
            company: $company,
            catalogue: $catalogue,
            planIds: $planIds,
            tenure: SubscriptionTenure::Yearly,
            commonRenewalDate: CarbonImmutable::now()->addYear(),
            remark: 'Local demo: draft subscription fixture',
        );
    }

    /**
     * @param  array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}  $catalogues
     */
    private function ensureManualEnquiry(
        EnquiryService $enquiries,
        Company $company,
        Branch $branch,
        User $actor,
        Party $customer,
        array $catalogues,
        string $fixtureKey,
        int $index,
    ): void {
        $marker = '['.$fixtureKey.']';
        $existing = Enquiry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('instructions', 'like', '%'.$fixtureKey.'%')
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            return;
        }

        $enquiries->createManual($company, $branch, $actor, [
            'customer_party_id' => $customer->id,
            'pickup_location' => $index % 2 === 0 ? 'Pune Warehouse' : 'Mumbai Dock',
            'delivery_location' => $index % 2 === 0 ? 'Nagpur Depot' : 'Nashik Market',
            'vehicle_type_id' => $catalogues['truck']->id,
            'vehicle_quantity' => $index === 1 ? 1 : 2,
            'expected_loading_date' => CarbonImmutable::now()->addDays(3 + $index)->toDateString(),
            'instructions' => 'Local demo manual enquiry '.$marker,
            'goods' => [
                [
                    'goods_type_id' => $catalogues['goods']->id,
                    'package_type_id' => $catalogues['package']->id,
                    'goods_description' => 'Demo FMCG cartons',
                    'package_count' => 10 + $index,
                    'actual_weight' => 100 + ($index * 10),
                    'charged_weight' => 110 + ($index * 10),
                ],
                [
                    'goods_type_id' => $catalogues['goods']->id,
                    'package_type_id' => $catalogues['package']->id,
                    'goods_description' => 'Demo spare parts',
                    'package_count' => 4,
                    'actual_weight' => 40,
                    'charged_weight' => 45,
                ],
            ],
        ]);
    }

    /**
     * @param  array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}  $catalogues
     */
    private function ensureWebsiteEnquiry(
        EnquiryService $enquiries,
        Company $company,
        array $catalogues,
        string $externalReference,
    ): void {
        $enquiries->createFromWebsite($company, [
            'external_reference' => $externalReference,
            'customer_name' => 'Website Lead '.$externalReference,
            'customer_phone' => '9123456780',
            'customer_email' => 'lead.'.strtolower($externalReference).'@demo.test',
            'pickup_location' => 'Website Pickup Yard',
            'delivery_location' => 'Website Delivery Gate',
            'vehicle_type_code' => $catalogues['truck']->code,
            'vehicle_quantity' => 1,
            'expected_loading_date' => CarbonImmutable::now()->addDays(5)->toDateString(),
            'instructions' => 'Local demo website enquiry ['.$externalReference.']',
            'goods' => [
                [
                    'goods_type_code' => $catalogues['goods']->code,
                    'package_type_code' => $catalogues['package']->code,
                    'goods_description' => 'Website booked cargo',
                    'package_count' => 8,
                    'actual_weight' => 80,
                    'charged_weight' => 90,
                ],
            ],
        ]);
    }

    private function slugCompany(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'company');
    }
}
