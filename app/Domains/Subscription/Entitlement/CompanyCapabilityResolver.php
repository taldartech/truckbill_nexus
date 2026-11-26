<?php

namespace App\Domains\Subscription\Entitlement;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\AccountingVoucher;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Single authoritative per-Company capability matrix.
 * Never resolves entitlements Owner-wide when an Owner holds multiple Companies.
 */
final class CompanyCapabilityResolver
{
    private readonly CoreModuleEntitlementResolver $modules;

    private readonly SubscriptionEntitlementGate $gate;

    private readonly SubscriptionClock $clock;

    public function __construct(
        ?CoreModuleEntitlementResolver $modules = null,
        ?SubscriptionEntitlementGate $gate = null,
        ?SubscriptionClock $clock = null,
    ) {
        $this->modules = $modules ?? app(CoreModuleEntitlementResolver::class);
        $this->gate = $gate ?? app(SubscriptionEntitlementGate::class);
        $this->clock = $clock ?? app(SubscriptionClock::class);
    }

    public static function invalidate(Company|string $company): void
    {
        $companyId = $company instanceof Company ? $company->id : $company;
        $versionKey = self::versionCacheKey($companyId);
        $current = (int) Cache::get($versionKey, 1);
        Cache::forever($versionKey, $current + 1);
    }

    /**
     * @return array{
     *     company_id: string,
     *     lifecycle_status: string,
     *     mutations_allowed: bool,
     *     historical_view_allowed: bool,
     *     is_read_only_grace_or_restricted: bool,
     *     active_modules: list<string>,
     *     historical_modules: list<string>,
     *     capabilities: array<string, array{read: bool, mutate: bool}>
     * }
     */
    public function resolve(Company $company): array
    {
        $version = (int) Cache::get(self::versionCacheKey($company->id), 1);
        $dayKey = $this->clock->now()->toDateString();
        $cacheKey = "company_capabilities:{$company->id}:v{$version}:{$dayKey}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($company): array {
            $decision = $this->gate->evaluate($company);
            $activeModules = $this->modules->entitledModules($company);
            $historicalModules = $this->modules->historicalModules($company);
            $activeAddons = $this->modules->activeAddonCodes($company);
            $historicalAddons = $this->modules->historicalAddonCodes($company);

            $activeSet = array_fill_keys(array_map(fn (SubscriptionModule $m): string => $m->value, $activeModules), true);
            $historicalSet = array_fill_keys(array_map(fn (SubscriptionModule $m): string => $m->value, $historicalModules), true);

            $mutationsAllowed = $decision['branch_mutations_allowed'];
            $historicalViewAllowed = $decision['branch_view_allowed'];

            $hasBroker = isset($activeSet[SubscriptionModule::Broker->value]);
            $hasOwnFleet = isset($activeSet[SubscriptionModule::OwnFleet->value]);
            $hasPartload = isset($activeSet[SubscriptionModule::Partload->value]);

            $hasBrokerRead = $historicalViewAllowed && isset($historicalSet[SubscriptionModule::Broker->value]);
            $hasOwnFleetRead = $historicalViewAllowed && isset($historicalSet[SubscriptionModule::OwnFleet->value]);
            $hasPartloadRead = $historicalViewAllowed && isset($historicalSet[SubscriptionModule::Partload->value]);

            $hasAnyCore = $hasBroker || $hasOwnFleet || $hasPartload;
            $hasAnyCoreRead = $hasBrokerRead || $hasOwnFleetRead || $hasPartloadRead;

            $hasAccounting = $mutationsAllowed && in_array('accounting', $activeAddons, true);
            $hasAccountingRead = $historicalViewAllowed && (
                in_array('accounting', $historicalAddons, true)
                || $this->hasHistoricalAccountingVouchers($company)
            );

            $isReadOnlyLifecycle = in_array($decision['status'], [
                SubscriptionLifecycleStatus::PaidGrace,
                SubscriptionLifecycleStatus::Restricted,
                SubscriptionLifecycleStatus::Cancelled,
            ], true);

            /** @var array<string, array{read: bool, mutate: bool}> $capabilities */
            $capabilities = [
                // Broker
                CompanyCapabilities::BROKER_LR => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_LORRY_HIRE => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_VOUCHERS => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_DAILY_PAYMENTS => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_DELIVERIES => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_LINKED_BILTIES => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_BOOK_BALANCE => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_INVOICES => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],
                CompanyCapabilities::BROKER_REGISTERS => ['read' => $hasBrokerRead, 'mutate' => $mutationsAllowed && $hasBroker],

                // OwnFleet
                CompanyCapabilities::OWNFLEET_LR => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_TRIPS => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_TRIP_EXPENSES => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_DIESEL => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_INVOICES => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_DRIVER_ATTENDANCE => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_TRUCK_TYPES => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_TYRE_LAYOUTS => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::OWNFLEET_EXPENSE_CATEGORIES => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],

                // Partload
                CompanyCapabilities::PARTLOAD_LR => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_PENDING_LOADING => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_IN_TRANSIT => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_PENDING_DELIVERY => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_CHALLANS => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_MAIN_CHALLANS => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_INWARDS => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_DELIVERY_RECEIPTS => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],
                CompanyCapabilities::PARTLOAD_INVOICES => ['read' => $hasPartloadRead, 'mutate' => $mutationsAllowed && $hasPartload],

                // Vehicle ownership
                CompanyCapabilities::VEHICLE_OWNERSHIP_OWN => ['read' => $hasOwnFleetRead, 'mutate' => $mutationsAllowed && $hasOwnFleet],
                CompanyCapabilities::VEHICLE_OWNERSHIP_MARKET => [
                    'read' => $hasBrokerRead || $hasPartloadRead,
                    'mutate' => $mutationsAllowed && ($hasBroker || $hasPartload),
                ],

                // Shared / Core / Accounting
                CompanyCapabilities::LR_UNIFIED => ['read' => $hasAnyCoreRead, 'mutate' => $mutationsAllowed && $hasAnyCore],
                CompanyCapabilities::CUSTOMER_INVOICES => [
                    'read' => $hasBrokerRead || $hasOwnFleetRead || $hasPartloadRead,
                    'mutate' => $mutationsAllowed && ($hasBroker || $hasOwnFleet || $hasPartload),
                ],
                CompanyCapabilities::CUSTOMER_PAYMENTS => [
                    'read' => $hasBrokerRead || $hasOwnFleetRead || $hasPartloadRead,
                    'mutate' => $mutationsAllowed && ($hasBroker || $hasOwnFleet || $hasPartload),
                ],
                CompanyCapabilities::ENQUIRIES => ['read' => $hasAnyCoreRead, 'mutate' => $mutationsAllowed && $hasAnyCore],
                CompanyCapabilities::ACCOUNTING => ['read' => $hasAccountingRead, 'mutate' => $hasAccounting],

                // Shared Masters (accessible when company has historical/active branch panel view;
                // mutations obey subscription gate + module ownership rules)
                CompanyCapabilities::MASTERS_PARTIES => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
                CompanyCapabilities::MASTERS_PARTIES_DRIVER_ROLE => [
                    'read' => $hasOwnFleetRead || $hasPartloadRead,
                    'mutate' => $mutationsAllowed && ($hasOwnFleet || $hasPartload),
                ],
                CompanyCapabilities::MASTERS_VEHICLES => ['read' => $hasAnyCoreRead, 'mutate' => $mutationsAllowed && $hasAnyCore],
                CompanyCapabilities::MASTERS_BANK_CASH => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
                CompanyCapabilities::MASTERS_STATIONS => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
                CompanyCapabilities::MASTERS_GOODS_TYPES => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
                CompanyCapabilities::MASTERS_PACKAGE_TYPES => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
                CompanyCapabilities::MASTERS_DOCUMENT_TYPES => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
                CompanyCapabilities::DOCUMENTS => ['read' => $historicalViewAllowed, 'mutate' => $mutationsAllowed],
            ];

            return [
                'company_id' => $company->id,
                'lifecycle_status' => $decision['status']->value,
                'mutations_allowed' => $mutationsAllowed,
                'historical_view_allowed' => $historicalViewAllowed,
                'is_read_only_grace_or_restricted' => $isReadOnlyLifecycle,
                'active_modules' => array_keys($activeSet),
                'historical_modules' => array_keys($historicalSet),
                'capabilities' => $capabilities,
            ];
        });
    }

    public function allowsRead(Company $company, string $capability): bool
    {
        $resolved = $this->resolve($company);

        return (bool) ($resolved['capabilities'][$capability]['read'] ?? false);
    }

    public function allowsMutate(Company $company, string $capability): bool
    {
        $resolved = $this->resolve($company);

        return (bool) ($resolved['capabilities'][$capability]['mutate'] ?? false);
    }

    /**
     * Allowed Vehicle ownership options for creation/mutation.
     *
     * @return list<VehicleOwnership>
     */
    public function allowedVehicleOwnerships(Company $company, bool $forMutation = true): array
    {
        $allowed = [];

        $check = fn (string $cap): bool => $forMutation
            ? $this->allowsMutate($company, $cap)
            : $this->allowsRead($company, $cap);

        if ($check(CompanyCapabilities::VEHICLE_OWNERSHIP_OWN)) {
            $allowed[] = VehicleOwnership::Own;
        }

        if ($check(CompanyCapabilities::VEHICLE_OWNERSHIP_MARKET)) {
            $allowed[] = VehicleOwnership::Market;
        }

        return $allowed;
    }

    public function allowsVehicleOwnership(
        Company $company,
        VehicleOwnership $ownership,
        bool $forMutation = true,
    ): bool {
        return in_array($ownership, $this->allowedVehicleOwnerships($company, $forMutation), true);
    }

    /**
     * Party roles selectable in Party Master / Quick Add for the Company's subscribed workflow.
     *
     * @return list<PartyRoleType>
     */
    public function allowedPartyRoles(Company $company): array
    {
        $roles = [
            PartyRoleType::Customer,
            PartyRoleType::Consignor,
            PartyRoleType::Consignee,
        ];

        $canTransporter = $this->allowsRead($company, CompanyCapabilities::BROKER_LR)
            || $this->allowsRead($company, CompanyCapabilities::PARTLOAD_CHALLANS)
            || $this->allowsRead($company, CompanyCapabilities::ENQUIRIES);
        if ($canTransporter) {
            $roles[] = PartyRoleType::Transporter;
        }

        if ($this->allowsRead($company, CompanyCapabilities::MASTERS_PARTIES_DRIVER_ROLE)) {
            $roles[] = PartyRoleType::Driver;
        }

        $roles[] = PartyRoleType::GeneralVendor;
        $roles[] = PartyRoleType::PumpVendor;

        return $roles;
    }

    public function denyMessage(Company $company, string $capability): string
    {
        $resolved = $this->resolve($company);

        if (! $company->isActive()) {
            return 'This Company is deactivated.';
        }

        $readAllowed = (bool) ($resolved['capabilities'][$capability]['read'] ?? false);
        if ($readAllowed && $resolved['is_read_only_grace_or_restricted']) {
            return $resolved['lifecycle_status'] === SubscriptionLifecycleStatus::PaidGrace->value
                ? 'This Company subscription is in a view-only grace period. Renew the subscription to continue creating or modifying records.'
                : 'This Company subscription has expired or is restricted. Renew the subscription to continue creating or modifying records.';
        }

        $module = CompanyCapabilities::moduleLabel($capability);

        if ($resolved['lifecycle_status'] === SubscriptionLifecycleStatus::None->value) {
            return 'No active subscription covers this Company. Activate a subscription plan to continue.';
        }

        if ($module !== 'this module') {
            return "{$module} is not included in this Company's subscription.";
        }

        return 'This feature is not included in this Company\'s subscription.';
    }

    /**
     * Navigation and feature visibility for the authenticated user within an active Branch Panel.
     * Intersects Company capability + RBAC permission + branch/company status.
     *
     * @return array<string, bool>
     */
    public function navigationForUser(
        User $user,
        Company $company,
        Branch $branch,
        PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        CompanyAccessService $access = new CompanyAccessService,
    ): array {
        $isOwner = $access->isCompanyOwner($user, $company);

        $perm = fn (string $code): bool => $authorizer->allowsCustomer($user, $code, $company, $branch);
        $masterPerm = fn (string $code): bool => $isOwner || $authorizer->allowsCustomer($user, $code, $company, $branch);

        $capRead = fn (string $cap): bool => $this->allowsRead($company, $cap);
        $capMutate = fn (string $cap): bool => $this->allowsMutate($company, $cap);

        $canBrokerLr = $capRead(CompanyCapabilities::BROKER_LR);
        $canOwnFleet = $capRead(CompanyCapabilities::OWNFLEET_LR);
        $canPartload = $capRead(CompanyCapabilities::PARTLOAD_LR);

        return [
            // Operations sidebar
            'bilty_register' => $capRead(CompanyCapabilities::LR_UNIFIED) && $perm(PermissionCodes::LR_VIEW),
            'create_bilty' => $capMutate(CompanyCapabilities::LR_UNIFIED) && $perm(PermissionCodes::LR_CREATE),
            'partload_bilty_shortcut' => $canPartload && $capMutate(CompanyCapabilities::PARTLOAD_LR) && $perm(PermissionCodes::LR_CREATE),
            'broker_lorry_hires' => $canBrokerLr && $perm(PermissionCodes::BROKER_LORRY_HIRE_VIEW),
            'broker_vouchers' => $canBrokerLr && $perm(PermissionCodes::BROKER_VOUCHER_VIEW),
            'broker_daily_payments' => $canBrokerLr && $perm(PermissionCodes::BROKER_VOUCHER_VIEW),
            'broker_deliveries' => $canBrokerLr && $perm(PermissionCodes::BROKER_DELIVERY_VIEW),
            'broker_linked_bilties' => $canBrokerLr && $perm(PermissionCodes::BROKER_LINKED_BILTY_VIEW),
            'broker_transporter_balances' => $canBrokerLr && $perm(PermissionCodes::BROKER_BOOK_BALANCE_VIEW),
            'broker_registers' => $canBrokerLr && $perm(PermissionCodes::BROKER_LORRY_HIRE_VIEW),
            'customer_invoices' => $capRead(CompanyCapabilities::CUSTOMER_INVOICES)
                && (($canOwnFleet && $perm(PermissionCodes::INVOICE_VIEW))
                    || ($canBrokerLr && $perm(PermissionCodes::BROKER_INVOICE_VIEW))
                    || ($canPartload && ($perm(PermissionCodes::INVOICE_VIEW) || $perm(PermissionCodes::PARTLOAD_DR_VIEW)))),
            'customer_payments' => $capRead(CompanyCapabilities::CUSTOMER_PAYMENTS)
                && (($canOwnFleet && $perm(PermissionCodes::PAYMENT_VIEW))
                    || ($canBrokerLr && $perm(PermissionCodes::BROKER_INVOICE_VIEW))
                    || ($canPartload && ($perm(PermissionCodes::PAYMENT_VIEW) || $perm(PermissionCodes::PARTLOAD_DR_VIEW)))),
            'enquiries' => $capRead(CompanyCapabilities::ENQUIRIES) && $perm(PermissionCodes::ENQUIRY_VIEW),
            'ownfleet_vehicle_drivers' => $capRead(CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS)
                && ($perm(PermissionCodes::TRIP_VIEW) || $perm(PermissionCodes::PLATFORM_VIEW)),
            'partload_pending_loading' => $canPartload && $perm(PermissionCodes::LR_VIEW),
            'partload_in_transit' => $canPartload && $perm(PermissionCodes::PARTLOAD_CHALLAN_VIEW),
            'partload_pending_delivery' => $canPartload && $perm(PermissionCodes::PARTLOAD_INWARD_VIEW),
            'partload_challans' => $canPartload && $perm(PermissionCodes::PARTLOAD_CHALLAN_VIEW),
            'partload_main_challans' => $canPartload && $perm(PermissionCodes::PARTLOAD_CHALLAN_VIEW),
            'partload_invoices' => $canPartload
                && $capRead(CompanyCapabilities::PARTLOAD_INVOICES)
                && ($perm(PermissionCodes::INVOICE_VIEW) || $perm(PermissionCodes::PARTLOAD_DR_VIEW)),
            'accounting' => $capRead(CompanyCapabilities::ACCOUNTING)
                && $perm(PermissionCodes::BRANCH_ACCOUNTING_VIEW)
                && $perm(PermissionCodes::PLATFORM_VIEW_FREIGHT),

            // Masters sidebar
            'masters_parties' => $capRead(CompanyCapabilities::MASTERS_PARTIES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_vehicles' => $capRead(CompanyCapabilities::MASTERS_VEHICLES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_vehicle_drivers' => $capRead(CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_bank_cash' => $capRead(CompanyCapabilities::MASTERS_BANK_CASH) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_stations' => $capRead(CompanyCapabilities::MASTERS_STATIONS) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_goods_types' => $capRead(CompanyCapabilities::MASTERS_GOODS_TYPES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_truck_types' => $capRead(CompanyCapabilities::OWNFLEET_TRUCK_TYPES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_tyre_layouts' => $capRead(CompanyCapabilities::OWNFLEET_TYRE_LAYOUTS) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_document_types' => $capRead(CompanyCapabilities::MASTERS_DOCUMENT_TYPES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_expense_categories' => $capRead(CompanyCapabilities::OWNFLEET_EXPENSE_CATEGORIES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
            'masters_package_types' => $capRead(CompanyCapabilities::MASTERS_PACKAGE_TYPES) && $masterPerm(PermissionCodes::PLATFORM_VIEW),
        ];
    }

    private function hasHistoricalAccountingVouchers(Company $company): bool
    {
        return AccountingVoucher::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->exists();
    }

    private static function versionCacheKey(string $companyId): string
    {
        return "company_capabilities:ver:{$companyId}";
    }
}
