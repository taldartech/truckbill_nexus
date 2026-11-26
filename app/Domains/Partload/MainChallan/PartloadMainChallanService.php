<?php

namespace App\Domains\Partload\MainChallan;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Challan\PartloadChallanTotals;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadMainChallanStatus;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadChallan;
use App\Models\PartloadMainChallan;
use App\Models\PartloadMainChallanLine;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Main Challan consolidates Draft Partload Challans onto one onward vehicle movement.
 */
final class PartloadMainChallanService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly PartloadChallanService $challans = new PartloadChallanService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PartloadMainChallan>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = PartloadMainChallan::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['fromStation', 'toStation', 'vehicle', 'driver', 'lines'])
            ->orderByDesc('main_challan_date')
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }

        return $builder->paginate($perPage);
    }

    public function findForBranch(Company $company, Branch $branch, string $mainChallanId): PartloadMainChallan
    {
        $this->ensureTenantMatches($company, $branch);

        $main = PartloadMainChallan::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($mainChallanId)
            ->whereNull('deleted_at')
            ->with([
                'fromStation',
                'toStation',
                'vehicle',
                'driver',
                'toAccountParty',
                'lines.challan.fromStation',
                'lines.challan.toStation',
                'lines.challan.vehicle',
            ])
            ->first();

        if ($main === null) {
            throw new PartloadDomainException('Main Challan not found.');
        }

        return $main;
    }

    /**
     * @return Collection<int, PartloadChallan>
     */
    public function eligibleChallans(Company $company, Branch $branch): Collection
    {
        $this->ensureTenantMatches($company, $branch);

        return PartloadChallan::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('status', PartloadChallanStatus::Draft->value)
            ->whereNull('partload_main_challan_id')
            ->whereNull('deleted_at')
            ->with(['fromStation', 'toStation', 'vehicle', 'driver'])
            ->orderByDesc('challan_date')
            ->get();
    }

    /**
     * @param  array{
     *     main_challan_date?: string|null,
     *     from_station_id: string,
     *     to_station_id: string,
     *     vehicle_id: string,
     *     driver_party_id: string,
     *     partload_challan_ids: list<string>,
     *     truck_freight?: numeric-string|float|int|null,
     *     labour_charge?: numeric-string|float|int|null,
     *     kat?: numeric-string|float|int|null,
     *     home_delivery_charge?: numeric-string|float|int|null,
     *     other_charge?: numeric-string|float|int|null,
     *     cash_received?: numeric-string|float|int|null,
     *     cash_paid?: numeric-string|float|int|null,
     *     crossing_freight?: numeric-string|float|int|null,
     *     cartage_dalali_pf?: numeric-string|float|int|null,
     *     gst_amount?: numeric-string|float|int|null,
     *     to_account_party_id?: string|null,
     *     remark?: string|null,
     *     dispatch?: bool
     * }  $payload
     */
    public function create(Company $company, Branch $branch, User $actor, array $payload): PartloadMainChallan
    {
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertPartloadEntitled($company);
        $this->ensureTenantMatches($company, $branch);

        $fromStation = $this->requireStation((string) $payload['from_station_id']);
        $toStation = $this->requireStation((string) $payload['to_station_id']);
        if ($fromStation->id === $toStation->id) {
            throw ValidationException::withMessages([
                'to_station_id' => 'From and To stations must differ.',
            ]);
        }

        $vehicle = $this->requireVehicle($company, (string) $payload['vehicle_id']);
        $driverId = $this->requireDriver($company, (string) $payload['driver_party_id']);
        $challanIds = array_values(array_unique(array_map('strval', $payload['partload_challan_ids'])));
        if ($challanIds === []) {
            throw ValidationException::withMessages([
                'partload_challan_ids' => 'Select at least one Challan.',
            ]);
        }

        $dispatch = (bool) ($payload['dispatch'] ?? false);

        try {
            return $this->auditedTransaction->run(function () use (
                $company,
                $branch,
                $actor,
                $payload,
                $fromStation,
                $toStation,
                $vehicle,
                $driverId,
                $challanIds,
                $dispatch,
            ): PartloadMainChallan {
                return $this->withTenant($company, $branch, function () use (
                    $company,
                    $branch,
                    $actor,
                    $payload,
                    $fromStation,
                    $toStation,
                    $vehicle,
                    $driverId,
                    $challanIds,
                    $dispatch,
                ): PartloadMainChallan {
                    $challans = $this->lockEligibleChallans($company, $branch, $challanIds);
                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::PartloadMainChallan,
                        scopeType: DocumentNumberScopeType::Branch,
                        branchId: $branch->id,
                    );

                    $aggregates = $this->aggregateChallans($challans);
                    $amounts = $this->normalizeAmounts($payload);
                    $totalAmount = PartloadChallanTotals::computeTotal([
                        ...$amounts,
                        'cash_payment' => $amounts['cash_paid'],
                        'to_pay_amount' => $aggregates['to_pay_amount'],
                    ]);
                    $totalAmount = bcadd(
                        $totalAmount,
                        number_format((float) $amounts['other_charge'], 2, '.', ''),
                        2,
                    );

                    $main = PartloadMainChallan::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'main_challan_number' => $allocation['number'],
                        'main_challan_date' => filled($payload['main_challan_date'] ?? null)
                            ? CarbonImmutable::parse((string) $payload['main_challan_date'])->toDateString()
                            : now()->toDateString(),
                        'from_station_id' => $fromStation->id,
                        'to_station_id' => $toStation->id,
                        'vehicle_id' => $vehicle->id,
                        'driver_party_id' => $driverId,
                        'ownership_mode' => $vehicle->ownership instanceof VehicleOwnership
                            ? $vehicle->ownership
                            : VehicleOwnership::from((string) $vehicle->ownership),
                        'status' => PartloadMainChallanStatus::Draft,
                        'truck_freight' => $amounts['truck_freight'],
                        'labour_charge' => $amounts['labour_charge'],
                        'kat' => $amounts['kat'],
                        'home_delivery_charge' => $amounts['home_delivery_charge'],
                        'other_charge' => $amounts['other_charge'],
                        'cash_received' => $amounts['cash_received'],
                        'cash_paid' => $amounts['cash_paid'],
                        'total_packages' => $aggregates['total_packages'],
                        'total_weight_kg' => $aggregates['total_weight_kg'],
                        'to_pay_amount' => $aggregates['to_pay_amount'],
                        'paid_amount' => $aggregates['paid_amount'],
                        'crossing_freight' => $amounts['crossing_freight'],
                        'cartage_dalali_pf' => $amounts['cartage_dalali_pf'],
                        'gst_amount' => $amounts['gst_amount'],
                        'total_amount' => $totalAmount,
                        'to_account_party_id' => filled($payload['to_account_party_id'] ?? null)
                            ? (string) $payload['to_account_party_id']
                            : null,
                        'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                        'created_by_user_id' => $actor->id,
                    ]);

                    foreach ($challans->values() as $index => $challan) {
                        PartloadMainChallanLine::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'partload_main_challan_id' => $main->id,
                            'partload_challan_id' => $challan->id,
                            'position' => $index + 1,
                        ]);

                        $challan->forceFill([
                            'partload_main_challan_id' => $main->id,
                        ])->save();
                    }

                    $this->audit->write(
                        AuditAction::Created,
                        PartloadMainChallan::class,
                        $main->id,
                        remark: 'Partload Main Challan created',
                        before: null,
                        after: [
                            'main_challan_number' => $main->main_challan_number,
                            'challan_count' => $challans->count(),
                        ],
                    );

                    if ($dispatch) {
                        return $this->dispatchWithinTenant($main->fresh(['lines.challan']), $actor);
                    }

                    return $main->fresh([
                        'fromStation',
                        'toStation',
                        'vehicle',
                        'driver',
                        'lines.challan',
                    ]) ?? $main;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new PartloadConflictException('Main Challan number conflict.', previous: $e);
        }
    }

    public function dispatch(PartloadMainChallan $main, User $actor): PartloadMainChallan
    {
        $company = Company::query()->findOrFail($main->company_id);
        $branch = Branch::query()->findOrFail($main->branch_id);
        $this->assertCanDispatch($actor, $company, $branch);
        $this->assertPartloadEntitled($company);

        return $this->auditedTransaction->run(function () use ($main, $company, $branch, $actor): PartloadMainChallan {
            return $this->withTenant($company, $branch, function () use ($main, $actor): PartloadMainChallan {
                return $this->dispatchWithinTenant($main, $actor);
            });
        });
    }

    public function cancel(PartloadMainChallan $main, User $actor, string $remark): PartloadMainChallan
    {
        $company = Company::query()->findOrFail($main->company_id);
        $branch = Branch::query()->findOrFail($main->branch_id);
        $this->assertCanCancel($actor, $company, $branch);
        $this->assertPartloadEntitled($company);

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages(['cancellation_remark' => 'Cancellation remark is required.']);
        }

        return $this->auditedTransaction->run(function () use ($main, $company, $branch, $remark): PartloadMainChallan {
            return $this->withTenant($company, $branch, function () use ($main, $remark): PartloadMainChallan {
                $locked = PartloadMainChallan::query()
                    ->whereKey($main->id)
                    ->lockForUpdate()
                    ->with('lines')
                    ->firstOrFail();

                if ($locked->status === PartloadMainChallanStatus::Cancelled) {
                    return $locked;
                }

                if ($locked->status === PartloadMainChallanStatus::Dispatched) {
                    throw new PartloadConflictException('Dispatched Main Challans cannot be cancelled.');
                }

                $before = ['status' => $locked->status->value];

                foreach ($locked->lines->whereNull('deleted_at') as $line) {
                    PartloadChallan::query()
                        ->whereKey($line->partload_challan_id)
                        ->where('partload_main_challan_id', $locked->id)
                        ->update(['partload_main_challan_id' => null]);
                    $line->delete();
                }

                $locked->forceFill([
                    'status' => PartloadMainChallanStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_remark' => $remark,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    PartloadMainChallan::class,
                    $locked->id,
                    remark: 'Partload Main Challan cancelled',
                    before: $before,
                    after: ['status' => PartloadMainChallanStatus::Cancelled->value],
                );

                return $locked->fresh() ?? $locked;
            });
        });
    }

    private function dispatchWithinTenant(PartloadMainChallan $main, User $actor): PartloadMainChallan
    {
        $locked = PartloadMainChallan::query()
            ->whereKey($main->id)
            ->lockForUpdate()
            ->with(['lines.challan'])
            ->firstOrFail();

        if ($locked->status === PartloadMainChallanStatus::Dispatched) {
            return $locked;
        }

        if ($locked->status !== PartloadMainChallanStatus::Draft) {
            throw new PartloadConflictException('Only Draft Main Challans can be dispatched.');
        }

        $lines = $locked->lines->whereNull('deleted_at');
        if ($lines->isEmpty()) {
            throw new PartloadConflictException('Cannot dispatch a Main Challan without Challans.');
        }

        $before = ['status' => $locked->status->value];
        $locked->forceFill([
            'status' => PartloadMainChallanStatus::Dispatched,
            'dispatched_at' => now(),
        ])->save();

        foreach ($lines as $line) {
            $challan = $line->challan;
            if ($challan === null) {
                continue;
            }
            $this->challans->dispatchFromMain($challan, $actor);
        }

        $this->audit->write(
            AuditAction::Updated,
            PartloadMainChallan::class,
            $locked->id,
            remark: 'Partload Main Challan dispatched',
            before: $before,
            after: ['status' => PartloadMainChallanStatus::Dispatched->value],
        );

        return $locked->fresh(['lines.challan', 'fromStation', 'toStation', 'vehicle', 'driver']) ?? $locked;
    }

    /**
     * @param  list<string>  $challanIds
     * @return Collection<int, PartloadChallan>
     */
    private function lockEligibleChallans(Company $company, Branch $branch, array $challanIds): Collection
    {
        $challans = PartloadChallan::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $challanIds)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($challans->count() !== count($challanIds)) {
            throw ValidationException::withMessages([
                'partload_challan_ids' => 'One or more Challans were not found.',
            ]);
        }

        foreach ($challanIds as $id) {
            $challan = $challans->get($id);
            if ($challan === null) {
                continue;
            }
            if ($challan->status !== PartloadChallanStatus::Draft) {
                throw new PartloadConflictException('Only Draft Challans can be added to a Main Challan.');
            }
            if (filled($challan->partload_main_challan_id)) {
                throw new PartloadConflictException('Challan '.$challan->challan_number.' is already on a Main Challan.');
            }
        }

        return collect($challanIds)->map(fn (string $id): PartloadChallan => $challans->get($id))->values();
    }

    /**
     * @param  Collection<int, PartloadChallan>  $challans
     * @return array{total_packages: string, total_weight_kg: string, to_pay_amount: string, paid_amount: string}
     */
    private function aggregateChallans(Collection $challans): array
    {
        $packages = '0.000';
        $weight = '0.000';
        $toPay = '0.00';
        $paid = '0.00';

        foreach ($challans as $challan) {
            $packages = bcadd($packages, number_format((float) $challan->total_packages, 3, '.', ''), 3);
            $weight = bcadd($weight, number_format((float) $challan->total_weight_kg, 3, '.', ''), 3);
            $toPay = bcadd($toPay, number_format((float) $challan->to_pay_amount, 2, '.', ''), 2);
            $paid = bcadd($paid, number_format((float) $challan->paid_amount, 2, '.', ''), 2);
        }

        return [
            'total_packages' => $packages,
            'total_weight_kg' => $weight,
            'to_pay_amount' => $toPay,
            'paid_amount' => $paid,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     truck_freight: string,
     *     labour_charge: string,
     *     kat: string,
     *     home_delivery_charge: string,
     *     other_charge: string,
     *     cash_received: string,
     *     cash_paid: string,
     *     crossing_freight: string,
     *     cartage_dalali_pf: string,
     *     gst_amount: string
     * }
     */
    private function normalizeAmounts(array $payload): array
    {
        $keys = [
            'truck_freight',
            'labour_charge',
            'kat',
            'home_delivery_charge',
            'other_charge',
            'cash_received',
            'cash_paid',
            'crossing_freight',
            'cartage_dalali_pf',
            'gst_amount',
        ];

        $amounts = [];
        foreach ($keys as $key) {
            $amounts[$key] = number_format((float) ($payload[$key] ?? 0), 2, '.', '');
        }

        return $amounts;
    }

    private function requireStation(string $stationId): Station
    {
        $station = Station::query()->whereKey($stationId)->whereNull('deleted_at')->first();
        if ($station === null) {
            throw ValidationException::withMessages(['from_station_id' => 'Station was not found.']);
        }

        return $station;
    }

    private function requireVehicle(Company $company, string $vehicleId): Vehicle
    {
        $vehicle = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicleId)
            ->whereNull('deleted_at')
            ->first();

        if ($vehicle === null) {
            throw ValidationException::withMessages(['vehicle_id' => 'Vehicle was not found.']);
        }

        return $vehicle;
    }

    private function requireDriver(Company $company, string $driverPartyId): string
    {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($driverPartyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages(['driver_party_id' => 'Driver was not found.']);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages(['driver_party_id' => 'Selected party is not a Driver.']);
        }

        return $party->id;
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_CREATE, $company, $branch)) {
            throw new PartloadAuthorizationException('Not authorized to create Main Challans.');
        }
    }

    private function assertCanDispatch(User $actor, Company $company, Branch $branch): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_DISPATCH, $company, $branch)) {
            throw new PartloadAuthorizationException('Not authorized to dispatch Main Challans.');
        }
    }

    private function assertCanCancel(User $actor, Company $company, Branch $branch): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PARTLOAD_CHALLAN_CANCEL, $company, $branch)) {
            throw new PartloadAuthorizationException('Not authorized to cancel Main Challans.');
        }
    }

    private function assertPartloadEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Partload)) {
            throw new PartloadAuthorizationException('Partload module is not entitled for this company.');
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
}
