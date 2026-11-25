<?php

namespace App\Domains\Broker;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\User;

/**
 * Branch Dashboard Broker operational snapshot.
 */
final class BrokerOperationalOverview
{
    public function __construct(
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    /**
     * @return array{
     *     entitled: bool,
     *     can_view_hires: bool,
     *     metrics: array{
     *         bilty_awaiting_hire: int,
     *         open_hires: int
     *     },
     *     links: array<string, string|null>
     * }|null
     */
    public function forBranch(Company $company, Branch $branch, User $actor): ?array
    {
        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            return null;
        }

        $canView = $this->authorizer->allowsCustomer(
            $actor,
            PermissionCodes::BROKER_LORRY_HIRE_VIEW,
            $company,
            $branch,
        );

        $biltyAwaitingHire = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::Broker->value)
            ->where('status', LrStatus::Pending->value)
            ->whereNull('deleted_at')
            ->whereDoesntHave('lorryHire', fn ($query) => $query->whereNull('deleted_at'))
            ->count();

        $openHires = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->whereIn('status', [
                LorryHireStatus::Pending->value,
                LorryHireStatus::Active->value,
            ])
            ->count();

        return [
            'entitled' => true,
            'can_view_hires' => $canView,
            'metrics' => [
                'bilty_awaiting_hire' => $biltyAwaitingHire,
                'open_hires' => $openHires,
            ],
            'links' => [
                'bilty_register' => route('branch.lorry-receipts.index', [
                    'ownership' => 'market',
                ], absolute: false),
                'lorry_hires' => route('branch.broker.lorry-hires.index', absolute: false),
                'vouchers' => route('branch.broker.vouchers.index', absolute: false),
            ],
        ];
    }
}
