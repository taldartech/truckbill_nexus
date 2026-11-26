<?php

namespace App\Domains\Partload\Reports;

use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

/**
 * Pending Loading register — Partload Bilties awaiting Challan consolidation.
 */
final class PartloadPendingLoadingService
{
    public function __construct(
        private readonly PartloadChallanService $challans = new PartloadChallanService,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array{count: int, packages: numeric-string, weight_kg: numeric-string, freight_total: numeric-string},
     * }
     */
    public function snapshot(
        Company $company,
        Branch $branch,
        User $actor,
        ?string $fromStationId = null,
        ?string $toStationId = null,
    ): array {
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_VIEW);
        $this->ensureTenantMatches($company, $branch);

        return $this->withTenant($company, $branch, function () use ($company, $branch, $fromStationId, $toStationId): array {
            $bilties = $this->challans->eligibleBilties($company, $branch, $fromStationId, $toStationId);

            $rows = array_values($bilties->map(fn (LorryReceipt $lr): array => $this->row($lr))->all());

            $packages = '0';
            $weightKg = '0.000';
            $freight = '0.00';

            foreach ($bilties as $lr) {
                $rowPackages = $this->packageTotal($lr);
                $rowWeight = $this->weightTotalKg($lr);
                $packages = bcadd($packages, $rowPackages, 0);
                $weightKg = bcadd($weightKg, $rowWeight, 3);
                $freight = bcadd($freight, $this->money($lr->freight_total), 2);
            }

            return [
                'rows' => $rows,
                'totals' => [
                    'count' => count($rows),
                    'packages' => $packages,
                    'weight_kg' => $weightKg,
                    'freight_total' => $freight,
                ],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LorryReceipt $lr): array
    {
        $packages = $this->packageTotal($lr);
        $weightKg = $this->weightTotalKg($lr);

        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->toDateString(),
            'billing_customer_name' => $lr->billingCustomer?->name,
            'consignor_name' => $lr->consignor?->name,
            'consignee_name' => $lr->consignee?->name,
            'from_station_id' => $lr->from_station_id,
            'from_station_name' => $lr->fromStation?->name,
            'to_station_id' => $lr->to_station_id,
            'to_station_name' => $lr->toStation?->name,
            'packages' => $packages,
            'weight_kg' => $weightKg,
            'weight_mt' => bcdiv($weightKg, '1000', 3),
            'freight_total' => (string) ($lr->freight_total ?? '0'),
            'freight_payment_term' => $lr->freight_payment_term?->value,
            'freight_payment_term_label' => $lr->freight_payment_term?->label(),
        ];
    }

    /**
     * @return numeric-string
     */
    private function packageTotal(LorryReceipt $lr): string
    {
        $total = '0';
        foreach ($lr->goodsRows as $row) {
            $total = bcadd($total, $this->quantity($row->package_count, 0), 0);
        }

        return $total;
    }

    /**
     * @return numeric-string
     */
    private function weightTotalKg(LorryReceipt $lr): string
    {
        $total = '0.000';
        foreach ($lr->goodsRows as $row) {
            $total = bcadd($total, $this->quantity($row->charged_weight ?? $row->actual_weight, 3), 3);
        }

        return $total;
    }

    private function assertEntitled(Company $company): void
    {
        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Partload)) {
            throw new PartloadAuthorizationException('Partload module is not entitled for this company.');
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new PartloadAuthorizationException('You are not authorized for this Partload action.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new PartloadDomainException('Branch does not belong to company.');
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * @return numeric-string
     */
    private function quantity(mixed $value, int $scale): string
    {
        return number_format((float) ($value ?? 0), $scale, '.', '');
    }
}
