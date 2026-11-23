<?php

namespace Database\Seeders;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Crm\Enums\InterestedProduct;
use App\Domains\Crm\Enums\LeadSource;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Enums\SupportTicketPriority;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Domains\Crm\LeadService;
use App\Domains\Crm\SupportTicketService;
use App\Domains\Enquiry\EnquiryAssignmentService;
use App\Domains\Enquiry\EnquiryConversionService;
use App\Domains\Enquiry\EnquiryQuotationService;
use App\Domains\Enquiry\EnquiryService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RoleAssignmentService;
use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Lifecycle\SubscriptionLifecycleService;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscription;
use App\Models\Crm\Lead;
use App\Models\Crm\SupportTicket;
use App\Models\Enquiry;
use App\Models\LorryReceipt;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Station;
use App\Models\SubscriptionCatalogueVersion;
use App\Models\SubscriptionPlan;
use App\Models\Trip;
use App\Models\TripInvoice;
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
        $this->call(TdsReferenceRulesSeeder::class);

        $this->ensureDemoRoles();

        $workspace = app(CompanyWorkspaceService::class);
        $access = app(CompanyAccessService::class);
        $rbac = app(RoleAssignmentService::class);
        $parties = app(PartyService::class);
        $enquiries = app(EnquiryService::class);
        $lorryReceipts = app(LorryReceiptService::class);
        $contracts = app(CompanySubscriptionContractService::class);

        $alpha = $this->seedCompanyWorkspace(
            workspace: $workspace,
            access: $access,
            rbac: $rbac,
            parties: $parties,
            enquiries: $enquiries,
            lorryReceipts: $lorryReceipts,
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
            lorryReceipts: $lorryReceipts,
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

        $sa = $this->ensureSuperAdminUser('sa.demo.lifecycle@demo.test', 'Demo Lifecycle SA', $password);
        $this->seedCrmFixtures($sa, $password);

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
        LorryReceiptService $lorryReceipts,
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

        // PO-MASTER-COMPANY-VISIBILITY demo: Alpha = Shared, Beta = Branch-wise Restricted.
        $visibility = str_contains(strtolower($companyName), 'beta')
            ? DataVisibility::BranchWiseRestricted
            : DataVisibility::SharedData;
        if ($company->data_visibility !== $visibility) {
            $company->forceFill(['data_visibility' => $visibility])->save();
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

        $transporterParty = $this->ensurePartyWithRoles(
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
                        'pan' => 'ABCDE'.str_pad(substr((string) abs(crc32($company->id)), 0, 4), 4, '0').'F',
                        'pan_status' => 'active',
                        'declaration' => true,
                        'declaration_type' => 'owner',
                        'goods_vehicles_count' => 4,
                    ],
                ],
            ],
            $companyName === self::COMPANY_A_NAME ? 'Thane' : 'Rajkot',
        );

        $consignorParty = $this->ensureParty(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':consignor-1',
            $companyName === self::COMPANY_A_NAME ? 'Alpha Consignor Mills' : 'Beta Consignor Works',
            [PartyRoleType::Consignor->value],
            $companyName === self::COMPANY_A_NAME ? 'Pune' : 'Surat',
        );

        $consigneeParty = $this->ensureParty(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':consignee-1',
            $companyName === self::COMPANY_A_NAME ? 'Alpha Consignee Depot' : 'Beta Consignee Yard',
            [PartyRoleType::Consignee->value],
            $companyName === self::COMPANY_A_NAME ? 'Nagpur' : 'Vadodara',
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

        $primaryDriver = $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':driver-primary',
            $companyName === self::COMPANY_A_NAME ? 'Alpha Primary Driver' : 'Beta Primary Driver',
            [
                [
                    'role' => PartyRoleType::Driver->value,
                    'attributes' => [
                        'driver_type' => 'permanent',
                        'license_number' => 'MH12P5'.substr((string) abs(crc32($company->id.'p')), 0, 6),
                        'joining_date' => '2024-06-01',
                    ],
                ],
            ],
            $companyName === self::COMPANY_A_NAME ? 'Pune' : 'Ahmedabad',
        );
        $substituteDriver = $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':driver-sub',
            $companyName === self::COMPANY_A_NAME ? 'Alpha Substitute Driver' : 'Beta Substitute Driver',
            [
                [
                    'role' => PartyRoleType::Driver->value,
                    'attributes' => [
                        'driver_type' => 'market',
                    ],
                ],
            ],
            $companyName === self::COMPANY_A_NAME ? 'Nashik' : 'Surat',
        );

        $vehicleHead = $this->ensureVehicle(
            $company,
            $head,
            'MH12DEMO'.substr((string) crc32($company->id.'1'), 0, 4),
            ownership: VehicleOwnership::Own,
            primaryDriverPartyId: $primaryDriver->id,
            truckTypeId: $catalogues['truck']->id,
            tyreLayoutId: $catalogues['tyre']->id,
        );
        $marketVehicle = $this->ensureVehicle(
            $company,
            $second,
            'MH14DEMO'.substr((string) crc32($company->id.'2'), 0, 4),
            ownership: VehicleOwnership::Market,
            primaryDriverPartyId: $substituteDriver->id,
            ownerPartyId: $transporterParty->id,
        );
        $this->ensureBank($company, $head, 'demo-bank:'.$company->id.':1');
        $fromStation = $this->ensureStation($owner, 'demo-station:'.$company->id.':1', $companyName === self::COMPANY_A_NAME ? 'Bhiwandi Hub' : 'Vatva Hub');
        $toStation = $this->ensureStation($owner, 'demo-station:'.$company->id.':2', $companyName === self::COMPANY_A_NAME ? 'Nagpur Depot' : 'Vadodara Yard');

        $modules = $companyName === self::COMPANY_A_NAME
            ? ['broker', 'ownfleet', 'partload']
            : ['broker', 'ownfleet'];
        $this->ensureActiveSubscription($contracts, $owner, $company, $modules);

        if ($companyName === self::COMPANY_A_NAME) {
            $this->ensureExtendedPartyFixtures($parties, $owner, $company, $head);
        }

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

        $lrCount = match ($profile) {
            'small' => 1,
            'large' => 4,
            default => 2,
        };

        for ($i = 1; $i <= $lrCount; $i++) {
            $branch = $i % 2 === 0 ? $second : $head;
            $access->activateContext($owner, $company, $branch);
            $this->ensureManualLorryReceipt(
                $lorryReceipts,
                $company,
                $branch,
                $owner,
                $consignorParty,
                $consigneeParty,
                $catalogues,
                $marketVehicle,
                'demo-lr:'.$company->id.':'.$branch->id.':'.$i,
                $i,
            );
        }

        $access->activateContext($owner, $company, $head);
        $this->ensureOwnFleetTripFixtures(
            $lorryReceipts,
            app(TripService::class),
            $company,
            $head,
            $owner,
            $vehicleHead,
            $fromStation,
            $toStation,
            $catalogues,
            'demo-ownfleet:'.$company->id.':pending',
        );
        $this->ensureOwnFleetInvoiceFixtures(
            app(LorryReceiptService::class),
            app(TripService::class),
            app(TripInvoiceService::class),
            app(TripInvoicePaymentService::class),
            $company,
            $head,
            $owner,
            $customerParty,
            $vehicleHead,
            $fromStation,
            $toStation,
            $catalogues,
        );

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
        if ($companyName === self::COMPANY_A_NAME) {
            $access->activateContext($owner, $company, $head);
            $this->ensureEnquiryCdfDemoFixtures(
                $access,
                $enquiries,
                app(EnquiryAssignmentService::class),
                app(EnquiryQuotationService::class),
                app(EnquiryConversionService::class),
                $company,
                $head,
                $owner,
                $customerParty,
                $transporterParty,
                $marketVehicle,
                $catalogues,
            );
        }
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
                    PermissionCodes::LR_VIEW,
                    PermissionCodes::LR_CREATE,
                    PermissionCodes::LR_EDIT,
                    PermissionCodes::LR_CANCEL,
                    PermissionCodes::TRIP_VIEW,
                    PermissionCodes::TRIP_CREATE,
                    PermissionCodes::TRIP_EDIT,
                    PermissionCodes::TRIP_DISPATCH,
                    PermissionCodes::TRIP_COMPLETE,
                    PermissionCodes::TRIP_CANCEL,
                    PermissionCodes::TRIP_DELETE,
                    PermissionCodes::INVOICE_VIEW,
                    PermissionCodes::INVOICE_CREATE,
                    PermissionCodes::INVOICE_EDIT,
                    PermissionCodes::INVOICE_CANCEL,
                    PermissionCodes::INVOICE_DELETE,
                    PermissionCodes::INVOICE_PRINT,
                    PermissionCodes::PAYMENT_VIEW,
                    PermissionCodes::PAYMENT_CREATE,
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
                    PermissionCodes::LR_VIEW,
                    PermissionCodes::LR_CREATE,
                    PermissionCodes::TRIP_VIEW,
                    PermissionCodes::TRIP_CREATE,
                    PermissionCodes::TRIP_EDIT,
                    PermissionCodes::TRIP_DISPATCH,
                    PermissionCodes::TRIP_COMPLETE,
                    PermissionCodes::INVOICE_VIEW,
                    PermissionCodes::INVOICE_CREATE,
                    PermissionCodes::PAYMENT_VIEW,
                    PermissionCodes::PAYMENT_CREATE,
                ],
            ],
            self::ROLE_VIEWER => [
                'name' => 'Demo Enquiry Viewer',
                'codes' => [
                    PermissionCodes::PLATFORM_VIEW,
                    PermissionCodes::PLATFORM_PRINT,
                    PermissionCodes::PLATFORM_VIEW_FREIGHT,
                    PermissionCodes::ENQUIRY_VIEW,
                    PermissionCodes::LR_VIEW,
                    PermissionCodes::TRIP_VIEW,
                    PermissionCodes::INVOICE_VIEW,
                    PermissionCodes::PAYMENT_VIEW,
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

    private function seedCrmFixtures(User $sa, string $password): void
    {
        $sales = User::query()->where('email', 'sales.demo@demo.test')->first()
            ?? User::factory()->internal()->create([
                'name' => 'Demo Sales Executive',
                'email' => 'sales.demo@demo.test',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

        $leads = app(LeadService::class);
        $tickets = app(SupportTicketService::class);

        $existingLead = Lead::query()->where('email', 'crm.lead@demo.test')->first();
        if ($existingLead === null) {
            $lead = $leads->create($sa, [
                'contact_name' => 'Demo CRM Lead',
                'email' => 'crm.lead@demo.test',
                'mobile' => '9876500123',
                'company_name' => 'Demo Prospect Transport',
                'source' => LeadSource::Website->value,
                'interested_product' => InterestedProduct::OwnFleet->value,
                'remarks' => 'Local demo CRM lead',
                'assigned_to_user_id' => $sales->id,
            ]);
            $leads->scheduleFollowUp($sa, $lead, [
                'due_at' => now()->addDay()->toDateTimeString(),
                'notes' => 'Demo discovery call',
                'assigned_to_user_id' => $sales->id,
            ]);
            $leads->transition($sa, $lead, LeadStatus::Contacted, 'Demo contacted');
        }

        $qualified = Lead::query()->where('email', 'crm.qualified@demo.test')->first();
        if ($qualified === null) {
            $lead = $leads->create($sa, [
                'contact_name' => 'Demo Qualified Lead',
                'email' => 'crm.qualified@demo.test',
                'mobile' => '9876500456',
                'company_name' => 'Demo Qualified Logistics',
                'source' => LeadSource::Referral->value,
                'interested_product' => InterestedProduct::Broker->value,
                'assigned_to_user_id' => $sales->id,
            ]);
            $leads->transition($sa, $lead, LeadStatus::Contacted, 'Demo contacted');
            $leads->transition($sa, $lead->fresh(), LeadStatus::Qualified, 'Demo qualified');
        }

        $existingTicket = SupportTicket::query()
            ->where('subject', 'Demo support: login help')
            ->first();
        if ($existingTicket === null) {
            $ticket = $tickets->create($sa, [
                'subject' => 'Demo support: login help',
                'description' => 'Local demo ticket for support workflow.',
                'category' => 'system',
                'priority' => SupportTicketPriority::Medium->value,
                'assigned_to_user_id' => $sales->id,
                'due_at' => now()->addDays(2)->toDateTimeString(),
            ]);
            $tickets->transition($sa, $ticket, SupportTicketStatus::InProgress, 'Demo in progress');
        }
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
     * @return array{truck: OwnerCatalogueItem, tyre: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}
     */
    private function ensureCatalogues(User $owner): array
    {
        $truck = $this->ensureCatalogue($owner, OwnerCatalogueType::TruckType, 'TATA407', 'Tata 407');
        $goods = $this->ensureCatalogue($owner, OwnerCatalogueType::GoodsType, 'GEN', 'General Cargo');
        $package = $this->ensureCatalogue($owner, OwnerCatalogueType::PackageType, 'BAG', 'Bag');
        $this->ensureCatalogue($owner, OwnerCatalogueType::DocumentType, 'PAN', 'PAN Card');
        $this->ensureCatalogue($owner, OwnerCatalogueType::ExpenseCategory, 'FUEL', 'Fuel');
        $tyre = $this->ensureCatalogue($owner, OwnerCatalogueType::TyreLayout, '6W', '6-Wheel Layout');

        return ['truck' => $truck, 'tyre' => $tyre, 'goods' => $goods, 'package' => $package];
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
        // Keep local-part ≤64 chars (RFC) — fixture keys include company UUIDs.
        $email = 'p'.substr(hash('sha256', $fixtureKey), 0, 20).'@party.demo.test';

        $existing = Party::query()
            ->where('company_id', $company->id)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $pan = null;
        foreach ($rolePayload as $role) {
            if ($role['role'] === PartyRoleType::Transporter->value) {
                $pan = $role['attributes']['pan'] ?? null;
                break;
            }
        }

        return $parties->create(
            actor: $owner,
            company: $company,
            branch: $branch,
            attributes: [
                'name' => $name,
                'contact_person' => 'Demo Contact',
                'contact_number' => '98'.str_pad(substr((string) abs(crc32($fixtureKey)), 0, 8), 8, '0'),
                'email' => $email,
                'pan' => $pan,
                'state' => 'Maharashtra',
                'city' => $city,
                'address' => 'Demo address for '.$fixtureKey,
                'is_gst_applicable' => false,
            ],
            roles: $rolePayload,
            remark: 'Local demo: create party '.$fixtureKey,
        );
    }

    private function ensureVehicle(
        Company $company,
        Branch $branch,
        string $registration,
        VehicleOwnership $ownership,
        ?string $primaryDriverPartyId = null,
        ?string $truckTypeId = null,
        ?string $tyreLayoutId = null,
        ?string $ownerPartyId = null,
    ): Vehicle {
        $existing = Vehicle::query()
            ->where('company_id', $company->id)
            ->where('registration_number', $registration)
            ->first();

        $attributes = [
            'ownership' => $ownership->value,
            'truck_type_id' => $ownership === VehicleOwnership::Own ? $truckTypeId : null,
            'tyre_layout_id' => $ownership === VehicleOwnership::Own ? $tyreLayoutId : null,
            'owner_party_id' => $ownership === VehicleOwnership::Market ? $ownerPartyId : null,
            'primary_driver_party_id' => $primaryDriverPartyId,
            'is_active' => true,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing->fresh() ?? $existing;
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
            ...$attributes,
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

    /**
     * @param  list<string>  $modules  SubscriptionModule values (broker|ownfleet|partload)
     */
    private function ensureActiveSubscription(
        CompanySubscriptionContractService $contracts,
        User $owner,
        Company $company,
        array $modules,
    ): void {
        $hasActive = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();

        if ($hasActive) {
            return;
        }

        $catalogue = SubscriptionCatalogueVersion::query()->where('is_active', true)->first();
        if ($catalogue === null) {
            return;
        }

        $planIds = [];
        foreach ($modules as $module) {
            $plan = SubscriptionPlan::query()
                ->where('catalogue_version_id', $catalogue->id)
                ->where('module', $module)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
            if ($plan !== null) {
                $planIds[] = (string) $plan->id;
            }
        }

        if ($planIds === []) {
            return;
        }

        $paidThrough = CarbonImmutable::now()->addYear();
        $draft = $contracts->createDraftContract(
            actor: $owner,
            company: $company,
            catalogue: $catalogue,
            planIds: $planIds,
            tenure: SubscriptionTenure::Yearly,
            commonRenewalDate: $paidThrough,
            remark: 'Local demo: subscription draft for activation',
        );

        app(SubscriptionLifecycleService::class)
            ->activateAfterVerifiedPayment(
                $draft,
                $paidThrough,
                $owner,
                'Local demo: activate verified subscription fixture',
            );
    }

    private function ensureExtendedPartyFixtures(
        PartyService $parties,
        User $owner,
        Company $company,
        Branch $branch,
    ): void {
        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $branch,
            'demo-party:'.$company->id.':driver-permanent',
            'Alpha Permanent Driver',
            [
                [
                    'role' => PartyRoleType::Driver->value,
                    'attributes' => [
                        'driver_type' => 'permanent',
                        'license_number' => 'MH12DEMO1001',
                        'joining_date' => '2024-01-15',
                    ],
                ],
            ],
            'Pune',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $branch,
            'demo-party:'.$company->id.':driver-market',
            'Alpha Market Driver',
            [
                [
                    'role' => PartyRoleType::Driver->value,
                    'attributes' => [
                        'driver_type' => 'market',
                    ],
                ],
            ],
            'Nashik',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':pump-vendor',
            'Alpha Pump Vendor',
            [
                [
                    'role' => PartyRoleType::PumpVendor->value,
                    'attributes' => [],
                ],
            ],
            'Pune',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':general-vendor',
            'Alpha General Vendor',
            [
                [
                    'role' => PartyRoleType::GeneralVendor->value,
                    'attributes' => [],
                ],
            ],
            'Mumbai',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            null,
            'demo-party:'.$company->id.':multi-role',
            'Alpha Customer Consignor',
            [
                ['role' => PartyRoleType::Customer->value, 'attributes' => []],
                ['role' => PartyRoleType::Consignor->value, 'attributes' => []],
            ],
            'Pune',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $branch,
            'demo-party:'.$company->id.':transporter-invalid-pan',
            'Alpha Invalid PAN Transporter',
            [
                [
                    'role' => PartyRoleType::Transporter->value,
                    'attributes' => [
                        'pan' => 'ABCDE1234F',
                        'pan_status' => 'invalid',
                        'declaration' => false,
                        'declaration_type' => 'owner',
                        'goods_vehicles_count' => 2,
                    ],
                ],
            ],
            'Thane',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $branch,
            'demo-party:'.$company->id.':transporter-agency',
            'Alpha Agency Transporter',
            [
                [
                    'role' => PartyRoleType::Transporter->value,
                    'attributes' => [
                        'pan' => 'AABCP5678K',
                        'pan_status' => 'active',
                        'declaration' => true,
                        'declaration_type' => 'agency',
                        'goods_vehicles_count' => 3,
                    ],
                ],
            ],
            'Nagpur',
        );

        $this->ensurePartyWithRoles(
            $parties,
            $owner,
            $company,
            $branch,
            'demo-party:'.$company->id.':transporter-fleet-heavy',
            'Alpha Heavy Fleet Transporter',
            [
                [
                    'role' => PartyRoleType::Transporter->value,
                    'attributes' => [
                        'pan' => 'AAHPP9012L',
                        'pan_status' => 'active',
                        'declaration' => true,
                        'declaration_type' => 'owner',
                        'goods_vehicles_count' => 15,
                    ],
                ],
            ],
            'Nashik',
        );
    }

    /**
     * Batch A OwnFleet fixture: pending OwnFleet LR + linked Pending Trip.
     *
     * @param  array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}  $catalogues
     */
    private function ensureOwnFleetTripFixtures(
        LorryReceiptService $lorryReceipts,
        TripService $trips,
        Company $company,
        Branch $branch,
        User $actor,
        Vehicle $vehicle,
        Station $fromStation,
        Station $toStation,
        array $catalogues,
        string $fixtureKey,
    ): void {
        $existingLr = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::OwnFleet->value)
            ->whereNull('deleted_at')
            ->whereHas('goodsRows', function ($query) use ($fixtureKey): void {
                $query->where('goods_description', 'like', '%'.$fixtureKey.'%');
            })
            ->first();

        if ($existingLr === null) {
            $existingLr = $lorryReceipts->create($company, $branch, $actor, [
                'workflow_context' => LrWorkflowContext::OwnFleet->value,
                'vehicle_id' => $vehicle->id,
                'number_mode' => 'auto',
                'lr_date' => CarbonImmutable::now()->subDay()->toDateString(),
                'from_station_id' => $fromStation->id,
                'to_station_id' => $toStation->id,
                'goods' => [
                    [
                        'goods_type_id' => $catalogues['goods']->id,
                        'package_type_id' => $catalogues['package']->id,
                        'goods_description' => 'OwnFleet demo cargo '.$fixtureKey,
                        'package_count' => 8,
                        'actual_weight' => 1200,
                        'charged_weight' => 1200,
                        'freight_rate_type' => FreightRateType::FixedAmount->value,
                        'freight_rate' => 18000,
                        'goods_value' => 275000,
                    ],
                ],
            ]);
        }

        $existingTrip = Trip::query()
            ->where('company_id', $company->id)
            ->where('lorry_receipt_id', $existingLr->id)
            ->whereNull('deleted_at')
            ->first();

        if ($existingTrip !== null) {
            return;
        }

        if ($vehicle->primary_driver_party_id === null) {
            return;
        }

        $trip = $trips->createFromLorryReceipt($existingLr, $actor, [
            'vehicle_id' => $vehicle->id,
        ]);

        $movements = app(TripMovementService::class);
        $pickupA = $movements->addPickup($trip, $actor, [
            'station_id' => $fromStation->id,
            'weight' => '800',
            'pickup_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'goods_description' => 'Demo OwnFleet pickup A',
        ]);
        $pickupB = $movements->addPickup($trip, $actor, [
            'station_id' => $fromStation->id,
            'weight' => '400',
            'pickup_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'goods_description' => 'Demo OwnFleet pickup B (partial example keeps 100 unallocated)',
        ]);
        $dropA = $movements->addDrop($trip, $actor, [
            'station_id' => $toStation->id,
        ]);
        $dropB = $movements->addDrop($trip, $actor, [
            'station_id' => $toStation->id,
        ]);

        $movements->upsertAllocation($trip, $actor, [
            'trip_pickup_id' => $pickupA->id,
            'trip_drop_id' => $dropA->id,
            'allocation_weight' => '500',
            'freight' => '9000',
        ]);
        $movements->upsertAllocation($trip, $actor, [
            'trip_pickup_id' => $pickupA->id,
            'trip_drop_id' => $dropB->id,
            'allocation_weight' => '300',
            'freight' => '5400',
        ]);
        $movements->upsertAllocation($trip, $actor, [
            'trip_pickup_id' => $pickupB->id,
            'trip_drop_id' => $dropA->id,
            'allocation_weight' => '300',
            'freight' => '0',
            'rate' => '0',
        ]);
    }

    /**
     * OwnFleet demo invoices: one partial and one paid (Batch F–G).
     */
    /**
     * @param  array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}  $catalogues
     */
    private function ensureOwnFleetInvoiceFixtures(
        LorryReceiptService $lorryReceipts,
        TripService $trips,
        TripInvoiceService $invoices,
        TripInvoicePaymentService $payments,
        Company $company,
        Branch $branch,
        User $actor,
        Party $billingCustomer,
        Vehicle $vehicle,
        Station $fromStation,
        Station $toStation,
        array $catalogues,
    ): void {
        if (TripInvoice::query()->where('company_id', $company->id)->where('invoice_number', 'like', 'DEMO-OF-INV%')->exists()) {
            return;
        }

        $completeTrip = function (LorryReceipt $lr, string $odometerStart, string $odometerEnd) use ($trips, $actor, $vehicle): Trip {
            $trip = $trips->createFromLorryReceipt($lr, $actor, ['vehicle_id' => $vehicle->id]);
            $movements = app(TripMovementService::class);
            $firstGoodsRow = $lr->goodsRows()->first();
            $pickupWeight = $firstGoodsRow !== null ? (string) $firstGoodsRow->charged_weight : '100';
            $pickup = $movements->addPickup($trip, $actor, [
                'station_id' => $lr->from_station_id,
                'weight' => $pickupWeight,
                'pickup_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
            ]);
            $drop = $movements->addDrop($trip, $actor, ['station_id' => $lr->to_station_id]);
            $movements->upsertAllocation($trip, $actor, [
                'trip_pickup_id' => $pickup->id,
                'trip_drop_id' => $drop->id,
                'allocation_weight' => $pickupWeight,
                'freight' => (string) $lr->freight_total,
            ]);
            $dispatched = $trips->dispatch($trip->fresh(), $actor, ['starting_odometer' => $odometerStart]);

            return $trips->complete($dispatched->fresh(), $actor, ['ending_odometer' => $odometerEnd]);
        };

        $lrPartial = $lorryReceipts->create($company, $branch, $actor, [
            'workflow_context' => LrWorkflowContext::OwnFleet->value,
            'vehicle_id' => $vehicle->id,
            'billing_customer_party_id' => $billingCustomer->id,
            'number_mode' => 'auto',
            'lr_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
            'from_station_id' => $fromStation->id,
            'to_station_id' => $toStation->id,
            'goods' => [[
                'goods_type_id' => $catalogues['goods']->id,
                'package_type_id' => $catalogues['package']->id,
                'goods_description' => 'OwnFleet invoice partial demo',
                'package_count' => 5,
                'actual_weight' => 900,
                'charged_weight' => 900,
                'freight_rate_type' => FreightRateType::FixedAmount->value,
                'freight_rate' => 22000,
                'goods_value' => 150000,
            ]],
        ]);
        $completeTrip($lrPartial, '4100', '4280');
        $partial = $invoices->createIndividual($company, $branch, $actor, $lrPartial->id, [
            'number_mode' => 'manual',
            'manual_number' => 'DEMO-OF-INV-PARTIAL',
        ]);
        $payments->record($partial, $actor, [
            'amount_received' => '5000',
            'tds_amount' => '100',
        ]);

        $lrPaid = $lorryReceipts->create($company, $branch, $actor, [
            'workflow_context' => LrWorkflowContext::OwnFleet->value,
            'vehicle_id' => $vehicle->id,
            'billing_customer_party_id' => $billingCustomer->id,
            'number_mode' => 'auto',
            'lr_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'from_station_id' => $fromStation->id,
            'to_station_id' => $toStation->id,
            'goods' => [[
                'goods_type_id' => $catalogues['goods']->id,
                'package_type_id' => $catalogues['package']->id,
                'goods_description' => 'OwnFleet invoice paid demo',
                'package_count' => 4,
                'actual_weight' => 700,
                'charged_weight' => 700,
                'freight_rate_type' => FreightRateType::FixedAmount->value,
                'freight_rate' => 12000,
                'goods_value' => 90000,
            ]],
        ]);
        $completeTrip($lrPaid, '4280', '4360');
        $paidInvoice = $invoices->createIndividual($company, $branch, $actor, $lrPaid->id, [
            'number_mode' => 'manual',
            'manual_number' => 'DEMO-OF-INV-PAID',
        ]);
        $payments->record($paidInvoice, $actor, [
            'fill_net' => true,
            'tds_amount' => '0',
        ]);
    }

    /**
     * @param  array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}  $catalogues
     */
    private function ensureManualLorryReceipt(
        LorryReceiptService $lorryReceipts,
        Company $company,
        Branch $branch,
        User $actor,
        Party $consignor,
        Party $consignee,
        array $catalogues,
        Vehicle $vehicle,
        string $fixtureKey,
        int $index,
    ): void {
        $existing = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('from_text', 'like', '%'.$fixtureKey.'%')
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            return;
        }

        $lorryReceipts->create($company, $branch, $actor, [
            'workflow_context' => 'broker',
            'vehicle_id' => $vehicle->id,
            'number_mode' => 'auto',
            'lr_date' => CarbonImmutable::now()->subDays($index)->toDateString(),
            'from_text' => 'Demo From '.$fixtureKey,
            'to_text' => 'Demo To '.$fixtureKey,
            'consignor_party_id' => $consignor->id,
            'consignee_party_id' => $consignee->id,
            'goods' => [
                [
                    'goods_type_id' => $catalogues['goods']->id,
                    'package_type_id' => $catalogues['package']->id,
                    'goods_description' => 'Demo LR cargo '.$index,
                    'package_count' => 10 + $index,
                    'actual_weight' => 250 + ($index * 25),
                    'charged_weight' => 260 + ($index * 25),
                    'freight_rate_type' => FreightRateType::PerPackageUnit->value,
                    'freight_rate' => 150,
                    'goods_value' => 25000 + ($index * 1000),
                ],
            ],
            'eway_bills' => [
                [
                    'ewb_number' => 'EWB'.substr((string) abs(crc32($fixtureKey)), 0, 10),
                    'ewb_date' => CarbonImmutable::now()->subDays($index)->toDateString(),
                    'valid_until' => CarbonImmutable::now()->addDays(7 + $index)->toDateString(),
                ],
            ],
            'additional_charges' => $index === 1 ? [
                [
                    'description' => 'Loading surcharge',
                    'amount' => 500,
                ],
            ] : [],
        ]);
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
     * Deterministic Company A fixture:
     * Manual Enquiry → Assign (already branch-scoped) → Quote → Preferred → Convert → LRs.
     *
     * Never select conversion candidates by created_at order alone (MySQL timestamp ties).
     *
     * @param  array{truck: OwnerCatalogueItem, goods: OwnerCatalogueItem, package: OwnerCatalogueItem}  $catalogues
     */
    private function ensureEnquiryCdfDemoFixtures(
        CompanyAccessService $access,
        EnquiryService $enquiries,
        EnquiryAssignmentService $assignments,
        EnquiryQuotationService $quotations,
        EnquiryConversionService $conversions,
        Company $company,
        Branch $branch,
        User $actor,
        Party $customerParty,
        Party $transporterParty,
        Vehicle $marketVehicle,
        array $catalogues,
    ): void {
        $assignMarker = 'demo-cdf-assign:'.$company->id;
        $convertFixtureKey = 'demo-cdf-convert:'.$company->id;

        $unassigned = Enquiry::query()
            ->where('company_id', $company->id)
            ->whereNull('branch_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        if ($unassigned !== null) {
            $assignments->assign($company, $branch, $unassigned, $actor, [
                'to_branch_id' => $branch->id,
                'to_assigned_user_id' => $actor->id,
                'remark' => 'Demo assign website enquiry '.$assignMarker,
            ]);
        }

        $convertEnquiry = Enquiry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('instructions', 'like', '%'.$convertFixtureKey.'%')
            ->whereNull('deleted_at')
            ->first();

        if ($convertEnquiry === null) {
            $convertEnquiry = $enquiries->createManual($company, $branch, $actor, [
                'customer_party_id' => $customerParty->id,
                'pickup_location' => 'CDF Demo Pickup Yard',
                'delivery_location' => 'CDF Demo Delivery Gate',
                'vehicle_type_id' => $catalogues['truck']->id,
                'vehicle_quantity' => 1,
                'expected_loading_date' => CarbonImmutable::now()->addDays(4)->toDateString(),
                'instructions' => 'Deterministic Option C conversion fixture ['.$convertFixtureKey.']',
                'goods' => [
                    [
                        'goods_type_id' => $catalogues['goods']->id,
                        'package_type_id' => $catalogues['package']->id,
                        'goods_description' => 'CDF conversion cargo',
                        'package_count' => 12,
                        'actual_weight' => 120,
                        'charged_weight' => 130,
                    ],
                ],
            ]);
        }

        $convertEnquiry = $convertEnquiry->fresh() ?? $convertEnquiry;

        if ($convertEnquiry->status->value === 'converted' || $convertEnquiry->conversion()->exists()) {
            return;
        }

        $quote = $convertEnquiry->quotations()
            ->where('transporter_party_id', $transporterParty->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if ($quote === null) {
            $quote = $quotations->create($company, $branch, $convertEnquiry, $actor, [
                'transporter_party_id' => $transporterParty->id,
                'quoted_freight' => 18500,
            ]);
        }

        if ($convertEnquiry->fresh()?->status->value !== 'selected') {
            $quotations->selectPreferred(
                $company,
                $branch,
                $convertEnquiry->fresh() ?? $convertEnquiry,
                $quote,
                $actor,
                'Demo preferred quote '.$convertFixtureKey,
            );
        }

        $convertEnquiry = $convertEnquiry->fresh() ?? $convertEnquiry;
        $access->activateContext($actor, $company, $branch);
        $preview = $conversions->preview($company, $branch, $convertEnquiry);

        if (($preview['eligible'] ?? false) !== true) {
            $errors = is_array($preview['eligibility_errors'] ?? null)
                ? implode('; ', $preview['eligibility_errors'])
                : 'unknown eligibility failure';

            throw new RuntimeException(
                'Local demo CDF conversion fixture is not eligible ('.$convertFixtureKey.'): '.$errors
            );
        }

        $suggested = is_array($preview['suggested_vehicles'] ?? null) ? $preview['suggested_vehicles'] : [];
        if ($suggested === []) {
            throw new RuntimeException(
                'Local demo CDF conversion fixture has no suggested vehicle allocations ('.$convertFixtureKey.').'
            );
        }

        $result = $conversions->convert($company, $branch, $convertEnquiry, $actor, [
            'workflow_context' => LrWorkflowContext::Broker->value,
            'vehicle_id' => $marketVehicle->id,
            'idempotency_key' => 'demo-convert-'.$convertFixtureKey,
            'remark' => 'Demo Option C conversion '.$convertFixtureKey,
            'vehicles' => array_map(
                fn (array $vehicle): array => ['goods' => $vehicle['goods']],
                array_values($suggested),
            ),
        ]);

        if ($result['conversion']->lorryReceiptLinks()->count() < 1) {
            throw new RuntimeException(
                'Local demo CDF conversion did not create LR links ('.$convertFixtureKey.').'
            );
        }
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
