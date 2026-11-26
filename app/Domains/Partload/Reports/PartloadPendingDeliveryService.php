<?php

namespace App\Domains\Partload\Reports;

use App\Domains\Partload\Enums\PartloadInwardDisposition;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadInwardLine;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

/**
 * Pending Delivery — Inward lines marked for Delivery without a Delivery Receipt.
 */
final class PartloadPendingDeliveryService
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array{count: int, packages: numeric-string, weight_kg: numeric-string},
     * }
     */
    public function snapshot(Company $company, Branch $branch, User $actor): array
    {
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_DR_VIEW);
        $this->ensureTenantMatches($company, $branch);

        return $this->withTenant($company, $branch, function () use ($company, $branch): array {
            $lines = PartloadInwardLine::query()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->where('disposition', PartloadInwardDisposition::Delivery->value)
                ->whereDoesntHave('deliveryReceipt')
                ->whereHas('inwardRegister', function ($query) use ($company, $branch): void {
                    $query->where('company_id', $company->id)
                        ->where('branch_id', $branch->id)
                        ->whereNull('deleted_at');
                })
                ->with([
                    'lorryReceipt.billingCustomer',
                    'lorryReceipt.toStation',
                    'inwardRegister',
                ])
                ->orderBy('created_at')
                ->limit(200)
                ->get();

            $rows = $lines->map(fn (PartloadInwardLine $line): array => [
                'id' => $line->id,
                'inward_number' => $line->inwardRegister?->inward_number,
                'lr_number' => $line->lorryReceipt?->lr_number,
                'billing_customer_name' => $line->lorryReceipt?->billingCustomer?->name,
                'to_station_name' => $line->lorryReceipt?->toStation?->name,
                'packages' => (string) $line->packages,
                'weight_kg' => (string) $line->weight_kg,
                'weight_mt' => bcdiv((string) $line->weight_kg, '1000', 3),
                'create_dr_url' => route('branch.partload.delivery-receipts.create', [
                    'lorry_receipt_id' => $line->lorry_receipt_id,
                    'partload_inward_line_id' => $line->id,
                ], absolute: false),
            ])->values()->all();

            $packages = '0';
            $weightKg = '0.000';
            foreach ($lines as $line) {
                $packages = bcadd($packages, $this->quantity($line->packages, 0), 0);
                $weightKg = bcadd($weightKg, $this->quantity($line->weight_kg, 3), 3);
            }

            return [
                'rows' => array_values($rows),
                'totals' => [
                    'count' => count($rows),
                    'packages' => $packages,
                    'weight_kg' => $weightKg,
                ],
            ];
        });
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
    private function quantity(mixed $value, int $scale): string
    {
        return number_format((float) ($value ?? 0), $scale, '.', '');
    }
}
