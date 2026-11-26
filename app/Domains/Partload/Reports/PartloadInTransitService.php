<?php

namespace App\Domains\Partload\Reports;

use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadChallan;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

/**
 * In-Transit register — Dispatched Partload Challans awaiting Inward.
 */
final class PartloadInTransitService
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array{count: int, packages: numeric-string, weight_kg: numeric-string, amount: numeric-string},
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
            $builder = PartloadChallan::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->where('status', PartloadChallanStatus::Dispatched->value)
                ->whereNull('deleted_at')
                ->with(['fromStation', 'toStation', 'vehicle', 'driver', 'lines'])
                ->orderByDesc('dispatched_at')
                ->orderByDesc('challan_date');

            if (filled($fromStationId)) {
                $builder->where('from_station_id', $fromStationId);
            }

            if (filled($toStationId)) {
                $builder->where('to_station_id', $toStationId);
            }

            $challans = $builder->limit(200)->get();

            $rows = $challans->map(fn (PartloadChallan $challan): array => [
                'id' => $challan->id,
                'challan_number' => $challan->challan_number,
                'challan_date' => $challan->challan_date?->toDateString(),
                'dispatched_at' => $challan->dispatched_at?->toDateTimeString(),
                'from_station_name' => $challan->fromStation?->name,
                'to_station_name' => $challan->toStation?->name,
                'vehicle_registration' => $challan->vehicle?->registration_number,
                'driver_name' => $challan->driver?->name,
                'line_count' => $challan->lines->count(),
                'total_packages' => (string) $challan->total_packages,
                'total_weight_kg' => (string) $challan->total_weight_kg,
                'total_weight_mt' => bcdiv((string) $challan->total_weight_kg, '1000', 3),
                'total_amount' => (string) $challan->total_amount,
                'show_url' => route('branch.partload.challans.show', $challan, absolute: false),
            ])->values()->all();

            $packages = '0';
            $weightKg = '0.000';
            $amount = '0.00';
            foreach ($challans as $challan) {
                $packages = bcadd($packages, $this->quantity($challan->total_packages, 0), 0);
                $weightKg = bcadd($weightKg, $this->quantity($challan->total_weight_kg, 3), 3);
                $amount = bcadd($amount, $this->money($challan->total_amount), 2);
            }

            return [
                'rows' => array_values($rows),
                'totals' => [
                    'count' => count($rows),
                    'packages' => $packages,
                    'weight_kg' => $weightKg,
                    'amount' => $amount,
                ],
            ];
        });
    }

    private function assertEntitled(Company $company): void
    {
        $capabilities = $this->capabilities ?? app(CompanyCapabilityResolver::class);
        if (! $capabilities->allowsRead($company, CompanyCapabilities::PARTLOAD_IN_TRANSIT)) {
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
