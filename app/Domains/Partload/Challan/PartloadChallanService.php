<?php

namespace App\Domains\Partload\Challan;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Enums\PartloadInwardDisposition;
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
use App\Models\LorryReceipt;
use App\Models\PartloadChallan;
use App\Models\PartloadChallanLine;
use App\Models\PartloadInwardLine;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Partload Challan consolidation — groups eligible Partload Bilties onto one vehicle movement (Rules §12).
 */
final class PartloadChallanService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PartloadChallan>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = PartloadChallan::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['fromStation', 'toStation', 'vehicle', 'driver', 'lines'])
            ->orderByDesc('challan_date')
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }

        return $builder->paginate($perPage);
    }

    public function findForBranch(Company $company, Branch $branch, string $challanId): PartloadChallan
    {
        $this->ensureTenantMatches($company, $branch);

        $challan = PartloadChallan::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with([
                'fromStation',
                'toStation',
                'vehicle',
                'driver',
                'toAccountParty',
                'lines.lorryReceipt.billingCustomer',
                'lines.toStation',
                'inwardRegister',
            ])
            ->find($challanId);

        if ($challan === null) {
            throw new PartloadDomainException('Challan was not found in this branch.');
        }

        return $challan;
    }

    /**
     * Eligible Pending Partload Bilties + transshipment Forward-disposition Bilties in this branch.
     *
     * @return Collection<int, LorryReceipt>
     */
    public function eligibleBilties(
        Company $company,
        Branch $branch,
        ?string $fromStationId = null,
        ?string $toStationId = null,
    ): Collection {
        $this->ensureTenantMatches($company, $branch);

        $loadedIds = PartloadChallanLine::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('challan', function (Builder $query): void {
                $query->whereNull('deleted_at')
                    ->whereIn('status', [
                        PartloadChallanStatus::Draft->value,
                        PartloadChallanStatus::Dispatched->value,
                        PartloadChallanStatus::Received->value,
                    ]);
            })
            ->pluck('lorry_receipt_id');

        $pendingBuilder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::Partload->value)
            ->where('status', LrStatus::Pending->value)
            ->whereNull('deleted_at')
            ->whereNull('cancelled_at')
            ->with(['billingCustomer', 'consignor', 'consignee', 'fromStation', 'toStation', 'goodsRows'])
            ->orderBy('lr_date')
            ->orderBy('lr_number');

        if ($loadedIds->isNotEmpty()) {
            $pendingBuilder->whereNotIn('id', $loadedIds->all());
        }

        if (filled($fromStationId)) {
            $pendingBuilder->where('from_station_id', $fromStationId);
        }

        if (filled($toStationId)) {
            $pendingBuilder->where('to_station_id', $toStationId);
        }

        $pending = $pendingBuilder->limit(200)->get();

        $activeOnDraftOrDispatchedIds = PartloadChallanLine::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('challan', function (Builder $query): void {
                $query->whereNull('deleted_at')
                    ->whereIn('status', [
                        PartloadChallanStatus::Draft->value,
                        PartloadChallanStatus::Dispatched->value,
                    ]);
            })
            ->pluck('lorry_receipt_id');

        $transshipBuilder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('workflow_context', LrWorkflowContext::Partload->value)
            ->where('status', LrStatus::InProgress->value)
            ->whereNull('deleted_at')
            ->whereNull('cancelled_at')
            ->whereDoesntHave('partloadDeliveryReceipt', fn (Builder $q) => $q->whereNull('deleted_at'))
            ->whereHas('partloadInwardLines', function (Builder $q) use ($branch, $fromStationId): void {
                $q->whereNull('deleted_at')
                    ->where('disposition', PartloadInwardDisposition::Forward->value)
                    ->whereHas('inwardRegister', function (Builder $reg) use ($branch, $fromStationId): void {
                        $reg->where('branch_id', $branch->id)->whereNull('deleted_at');
                        if (filled($fromStationId)) {
                            $reg->where('to_station_id', $fromStationId);
                        }
                    });
            })
            ->with([
                'billingCustomer',
                'consignor',
                'consignee',
                'fromStation',
                'toStation',
                'goodsRows',
                'partloadChallanLines' => fn ($q) => $q->whereNull('deleted_at')->whereHas('challan', fn (Builder $c) => $c->whereNull('deleted_at')->whereIn('status', [
                    PartloadChallanStatus::Draft->value,
                    PartloadChallanStatus::Dispatched->value,
                    PartloadChallanStatus::Received->value,
                ])),
                'partloadInwardLines' => fn ($q) => $q->whereNull('deleted_at')->with('inwardRegister')->orderByDesc('created_at'),
            ])
            ->orderBy('lr_date')
            ->orderBy('lr_number');

        if ($activeOnDraftOrDispatchedIds->isNotEmpty()) {
            $transshipBuilder->whereNotIn('id', $activeOnDraftOrDispatchedIds->all());
        }

        if (filled($toStationId)) {
            $transshipBuilder->where('to_station_id', $toStationId);
        }

        $transshipment = $transshipBuilder->limit(200)->get()->filter(
            fn (LorryReceipt $lr): bool => $this->isTransshippableAtBranch($lr, $branch, $fromStationId)
        );

        return $pending->concat($transshipment)->values();
    }

    /**
     * @return array{packages: numeric-string, weight_kg: numeric-string, is_transshipment: bool}
     */
    public function effectiveLoadingQuantities(LorryReceipt $lr): array
    {
        if ($lr->status === LrStatus::InProgress) {
            /** @var PartloadInwardLine|null $latestForward */
            $latestForward = $lr->partloadInwardLines
                ->whereNull('deleted_at')
                ->sortByDesc('created_at')
                ->first();

            if ($latestForward !== null && $latestForward->disposition === PartloadInwardDisposition::Forward) {
                return [
                    'packages' => number_format((float) $latestForward->packages, 3, '.', ''),
                    'weight_kg' => number_format((float) $latestForward->weight_kg, 3, '.', ''),
                    'is_transshipment' => true,
                ];
            }
        }

        $packages = '0.000';
        $weight = '0.000';
        foreach ($lr->goodsRows->whereNull('deleted_at') as $row) {
            $packages = number_format((float) $packages + (float) ($row->package_count ?? 0), 3, '.', '');
            $weight = number_format((float) $weight + (float) ($row->charged_weight ?? $row->actual_weight ?? 0), 3, '.', '');
        }

        return [
            'packages' => $packages,
            'weight_kg' => $weight,
            'is_transshipment' => false,
        ];
    }

    private function isTransshippableAtBranch(LorryReceipt $lr, Branch $branch, ?string $fromStationId = null): bool
    {
        $activeChallanCount = $lr->partloadChallanLines->count();
        $inwardLines = $lr->partloadInwardLines->whereNull('deleted_at')->sortByDesc('created_at')->values();
        if ($inwardLines->isEmpty() || $activeChallanCount !== $inwardLines->count()) {
            return false;
        }

        /** @var PartloadInwardLine $latest */
        $latest = $inwardLines->first();
        if ($latest->disposition !== PartloadInwardDisposition::Forward) {
            return false;
        }

        if ($latest->inwardRegister->branch_id !== $branch->id) {
            return false;
        }

        if (filled($fromStationId) && $latest->inwardRegister->to_station_id !== $fromStationId) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{
     *     challan_date?: string|null,
     *     from_station_id: string,
     *     to_station_id: string,
     *     vehicle_id: string,
     *     driver_party_id?: string|null,
     *     lorry_receipt_ids: list<string>,
     *     truck_freight?: numeric-string|float|int|null,
     *     labour_charge?: numeric-string|float|int|null,
     *     kat?: numeric-string|float|int|null,
     *     home_delivery_charge?: numeric-string|float|int|null,
     *     cash_received?: numeric-string|float|int|null,
     *     cash_payment?: numeric-string|float|int|null,
     *     crossing_freight?: numeric-string|float|int|null,
     *     cartage_dalali_pf?: numeric-string|float|int|null,
     *     gst_amount?: numeric-string|float|int|null,
     *     to_account_party_id?: string|null,
     *     remark?: string|null,
     *     dispatch?: bool,
     * }  $payload
     */
    public function create(Company $company, Branch $branch, User $actor, array $payload): PartloadChallan
    {
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_CREATE);
        $this->ensureTenantMatches($company, $branch);

        $fromStation = $this->requireStation($company, (string) $payload['from_station_id'], 'from_station_id');
        $toStation = $this->requireStation($company, (string) $payload['to_station_id'], 'to_station_id');
        if ($fromStation->id === $toStation->id) {
            throw ValidationException::withMessages([
                'to_station_id' => 'From and To stations must differ.',
            ]);
        }

        $vehicle = $this->requireVehicle($company, $branch, (string) $payload['vehicle_id']);
        $driverId = filled($payload['driver_party_id'] ?? null)
            ? (string) $payload['driver_party_id']
            : (string) ($vehicle->primary_driver_party_id ?? '');
        if ($driverId === '') {
            throw ValidationException::withMessages([
                'driver_party_id' => 'Driver is required for Challan creation.',
            ]);
        }
        $this->assertDriverParty($company, $branch, $driverId);

        $lrIds = array_values(array_unique(array_filter(
            $payload['lorry_receipt_ids'],
            static fn (string $id): bool => $id !== '',
        )));
        if ($lrIds === []) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Select at least one eligible Bilty.',
            ]);
        }

        $bilties = $this->loadEligibleBiltiesForCreate($company, $branch, $lrIds);
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
                $bilties,
                $dispatch,
            ): PartloadChallan {
                return $this->withTenant($company, $branch, function () use (
                    $company,
                    $branch,
                    $actor,
                    $payload,
                    $fromStation,
                    $toStation,
                    $vehicle,
                    $driverId,
                    $bilties,
                    $dispatch,
                ): PartloadChallan {
                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::PartloadChallan,
                        scopeType: DocumentNumberScopeType::Company,
                        branchId: null,
                    );

                    $lineSnapshots = $this->buildLineSnapshots($bilties);
                    $aggregates = $this->aggregateLines($lineSnapshots);
                    $amounts = $this->normalizeHeaderAmounts($payload);
                    $totalAmount = PartloadChallanTotals::computeTotal([
                        ...$amounts,
                        'to_pay_amount' => $aggregates['to_pay_amount'],
                    ]);

                    $challan = PartloadChallan::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'challan_number' => $allocation['number'],
                        'challan_date' => filled($payload['challan_date'] ?? null)
                            ? CarbonImmutable::parse((string) $payload['challan_date'])->toDateString()
                            : now()->toDateString(),
                        'from_station_id' => $fromStation->id,
                        'to_station_id' => $toStation->id,
                        'vehicle_id' => $vehicle->id,
                        'driver_party_id' => $driverId,
                        'ownership_mode' => $vehicle->ownership,
                        'status' => PartloadChallanStatus::Draft,
                        'truck_freight' => $amounts['truck_freight'],
                        'labour_charge' => $amounts['labour_charge'],
                        'kat' => $amounts['kat'],
                        'home_delivery_charge' => $amounts['home_delivery_charge'],
                        'cash_received' => $amounts['cash_received'],
                        'cash_payment' => $amounts['cash_payment'],
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

                    foreach ($lineSnapshots as $index => $snapshot) {
                        PartloadChallanLine::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'partload_challan_id' => $challan->id,
                            'lorry_receipt_id' => $snapshot['lorry_receipt_id'],
                            'to_station_id' => $snapshot['to_station_id'],
                            'position' => $index + 1,
                            'packages' => $snapshot['packages'],
                            'weight_kg' => $snapshot['weight_kg'],
                            'freight_amount' => $snapshot['freight_amount'],
                            'freight_payment_term' => $snapshot['freight_payment_term'],
                        ]);
                    }

                    $this->audit->write(
                        AuditAction::Created,
                        PartloadChallan::class,
                        $challan->id,
                        remark: 'Partload Challan created',
                        before: null,
                        after: [
                            'challan_number' => $challan->challan_number,
                            'status' => $challan->status->value,
                            'line_count' => count($lineSnapshots),
                        ],
                    );

                    if ($dispatch) {
                        return $this->dispatchWithinTenant($challan->fresh(['lines']), $actor);
                    }

                    return $challan->fresh(['lines', 'fromStation', 'toStation', 'vehicle', 'driver'])
                        ?? $challan;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new PartloadConflictException(
                'One or more selected Bilties are already loaded on an active Challan.',
                previous: $e,
            );
        }
    }

    public function dispatch(PartloadChallan $challan, User $actor): PartloadChallan
    {
        $company = Company::query()->findOrFail($challan->company_id);
        $branch = Branch::query()->findOrFail($challan->branch_id);
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_DISPATCH);
        $this->ensureTenantMatches($company, $branch);

        return $this->auditedTransaction->run(function () use ($challan, $actor, $company, $branch): PartloadChallan {
            return $this->withTenant($company, $branch, function () use ($challan, $actor): PartloadChallan {
                return $this->dispatchWithinTenant($challan->fresh(['lines']) ?? $challan, $actor, false);
            });
        });
    }

    /**
     * Dispatch a child Challan while its Main Challan is being dispatched (tenant already active).
     */
    public function dispatchFromMain(PartloadChallan $challan, User $actor): PartloadChallan
    {
        return $this->dispatchWithinTenant($challan->fresh(['lines']) ?? $challan, $actor, true);
    }

    public function cancel(PartloadChallan $challan, User $actor, string $remark): PartloadChallan
    {
        $company = Company::query()->findOrFail($challan->company_id);
        $branch = Branch::query()->findOrFail($challan->branch_id);
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_CHALLAN_CANCEL);
        $this->ensureTenantMatches($company, $branch);

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages([
                'cancellation_remark' => 'Cancellation remark is required.',
            ]);
        }

        return $this->auditedTransaction->run(function () use ($challan, $actor, $company, $branch, $remark): PartloadChallan {
            return $this->withTenant($company, $branch, function () use ($challan, $actor, $remark): PartloadChallan {
                $locked = PartloadChallan::query()
                    ->whereKey($challan->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === PartloadChallanStatus::Cancelled) {
                    return $locked;
                }

                if ($locked->status === PartloadChallanStatus::Received) {
                    throw new PartloadConflictException('Received Challans cannot be cancelled.');
                }

                if ($locked->inwardRegister()->whereNull('deleted_at')->exists()) {
                    throw new PartloadConflictException('Challan already has an Inward Register and cannot be cancelled.');
                }

                $before = ['status' => $locked->status->value];

                if ($locked->status === PartloadChallanStatus::Dispatched) {
                    $lineLrIds = PartloadChallanLine::query()
                        ->where('partload_challan_id', $locked->id)
                        ->whereNull('deleted_at')
                        ->pluck('lorry_receipt_id');

                    $priorInwardLrIds = PartloadInwardLine::query()
                        ->where('company_id', $locked->company_id)
                        ->whereIn('lorry_receipt_id', $lineLrIds)
                        ->whereNull('deleted_at')
                        ->pluck('lorry_receipt_id');

                    $revertableIds = $lineLrIds->diff($priorInwardLrIds)->values();
                    if ($revertableIds->isNotEmpty()) {
                        LorryReceipt::query()
                            ->whereIn('id', $revertableIds)
                            ->where('status', LrStatus::InProgress->value)
                            ->update(['status' => LrStatus::Pending->value]);
                    }
                }

                PartloadChallanLine::query()
                    ->where('partload_challan_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                $locked->forceFill([
                    'status' => PartloadChallanStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_remark' => $remark,
                ])->save();

                $this->audit->write(
                    AuditAction::Cancelled,
                    PartloadChallan::class,
                    $locked->id,
                    remark: $remark,
                    before: $before,
                    after: ['status' => PartloadChallanStatus::Cancelled->value],
                    cancellationReason: $remark,
                    cancellationAt: $locked->cancelled_at ?? now(),
                );

                $this->accounting->postPartloadChallanCancelled($locked, $actor, $remark);

                return $locked->fresh() ?? $locked;
            });
        });
    }

    private function dispatchWithinTenant(PartloadChallan $challan, User $actor, bool $fromMain = false): PartloadChallan
    {
        $locked = PartloadChallan::query()
            ->whereKey($challan->id)
            ->lockForUpdate()
            ->with('lines')
            ->firstOrFail();

        if ($locked->status === PartloadChallanStatus::Dispatched
            || $locked->status === PartloadChallanStatus::Received) {
            return $locked;
        }

        if ($locked->status !== PartloadChallanStatus::Draft) {
            throw new PartloadConflictException('Only Draft Challans can be dispatched.');
        }

        if (filled($locked->partload_main_challan_id) && ! $fromMain) {
            throw new PartloadConflictException('Challan is on a Main Challan; dispatch the Main Challan instead.');
        }

        if ($locked->lines->whereNull('deleted_at')->isEmpty()) {
            throw new PartloadConflictException('Cannot dispatch a Challan without Bilty lines.');
        }

        $before = ['status' => $locked->status->value];

        $locked->forceFill([
            'status' => PartloadChallanStatus::Dispatched,
            'dispatched_at' => now(),
        ])->save();

        $lrIds = $locked->lines->whereNull('deleted_at')->pluck('lorry_receipt_id');
        LorryReceipt::query()
            ->whereIn('id', $lrIds)
            ->where('status', LrStatus::Pending->value)
            ->update(['status' => LrStatus::InProgress->value]);

        $this->audit->write(
            AuditAction::Updated,
            PartloadChallan::class,
            $locked->id,
            remark: 'Partload Challan dispatched',
            before: $before,
            after: ['status' => PartloadChallanStatus::Dispatched->value],
        );

        $dispatched = $locked->fresh(['lines', 'fromStation', 'toStation', 'vehicle', 'driver']) ?? $locked;
        $this->accounting->postPartloadChallanDispatched($dispatched, $actor);

        return $dispatched;
    }

    /**
     * @param  list<string>  $lrIds
     * @return Collection<int, LorryReceipt>
     */
    private function loadEligibleBiltiesForCreate(Company $company, Branch $branch, array $lrIds): Collection
    {
        $bilties = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('workflow_context', LrWorkflowContext::Partload->value)
            ->whereIn('id', $lrIds)
            ->whereNull('deleted_at')
            ->with([
                'goodsRows',
                'partloadDeliveryReceipt',
                'partloadChallanLines' => fn ($q) => $q->whereNull('deleted_at')->whereHas('challan', fn (Builder $c) => $c->whereNull('deleted_at')->whereIn('status', [
                    PartloadChallanStatus::Draft->value,
                    PartloadChallanStatus::Dispatched->value,
                    PartloadChallanStatus::Received->value,
                ])),
                'partloadInwardLines' => fn ($q) => $q->whereNull('deleted_at')->with('inwardRegister')->orderByDesc('created_at'),
            ])
            ->get();

        if ($bilties->count() !== count($lrIds)) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'One or more Bilties were not found in this company.',
            ]);
        }

        foreach ($bilties as $bilty) {
            if ($bilty->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'lorry_receipt_ids' => "Bilty {$bilty->lr_number} is cancelled.",
                ]);
            }

            if ($bilty->from_station_id === null || $bilty->to_station_id === null) {
                throw ValidationException::withMessages([
                    'lorry_receipt_ids' => "Bilty {$bilty->lr_number} requires From and To stations.",
                ]);
            }

            if ($bilty->status === LrStatus::Pending) {
                if ($bilty->branch_id !== $branch->id) {
                    throw ValidationException::withMessages([
                        'lorry_receipt_ids' => "Bilty {$bilty->lr_number} does not belong to this branch.",
                    ]);
                }

                if ($bilty->partloadChallanLines->isNotEmpty()) {
                    throw new PartloadConflictException(
                        "Bilty {$bilty->lr_number} is already loaded on an active Challan.",
                    );
                }

                continue;
            }

            if ($bilty->status === LrStatus::InProgress
                && $bilty->partloadDeliveryReceipt === null
                && $this->isTransshippableAtBranch($bilty, $branch)
            ) {
                continue;
            }

            throw ValidationException::withMessages([
                'lorry_receipt_ids' => "Bilty {$bilty->lr_number} is not eligible for loading.",
            ]);
        }

        return $bilties->values();
    }

    /**
     * @param  Collection<int, LorryReceipt>  $bilties
     * @return list<array{
     *     lorry_receipt_id: string,
     *     to_station_id: string,
     *     packages: string,
     *     weight_kg: string,
     *     freight_amount: string,
     *     freight_payment_term: string|null
     * }>
     */
    private function buildLineSnapshots(Collection $bilties): array
    {
        $snapshots = [];

        foreach ($bilties as $bilty) {
            $quantities = $this->effectiveLoadingQuantities($bilty);
            $term = $bilty->freight_payment_term?->value;

            $snapshots[] = [
                'lorry_receipt_id' => $bilty->id,
                'to_station_id' => (string) $bilty->to_station_id,
                'packages' => $quantities['packages'],
                'weight_kg' => $quantities['weight_kg'],
                'freight_amount' => number_format((float) $bilty->freight_total, 2, '.', ''),
                'freight_payment_term' => $term,
            ];
        }

        return $snapshots;
    }

    /**
     * @param  list<array{packages: string, weight_kg: string, freight_amount: string, freight_payment_term: string|null}>  $lines
     * @return array{total_packages: string, total_weight_kg: string, to_pay_amount: string, paid_amount: string}
     */
    private function aggregateLines(array $lines): array
    {
        $packages = 0.0;
        $weight = 0.0;
        $toPay = 0.0;
        $paid = 0.0;

        foreach ($lines as $line) {
            $packages += (float) $line['packages'];
            $weight += (float) $line['weight_kg'];
            $freight = (float) $line['freight_amount'];
            if ($line['freight_payment_term'] === PartloadFreightPaymentTerm::ToPay->value) {
                $toPay += $freight;
            } elseif ($line['freight_payment_term'] === PartloadFreightPaymentTerm::Paid->value) {
                $paid += $freight;
            }
        }

        return [
            'total_packages' => number_format($packages, 3, '.', ''),
            'total_weight_kg' => number_format($weight, 3, '.', ''),
            'to_pay_amount' => number_format($toPay, 2, '.', ''),
            'paid_amount' => number_format($paid, 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     truck_freight: string,
     *     labour_charge: string,
     *     kat: string,
     *     home_delivery_charge: string,
     *     cash_received: string,
     *     cash_payment: string,
     *     crossing_freight: string,
     *     cartage_dalali_pf: string,
     *     gst_amount: string
     * }
     */
    private function normalizeHeaderAmounts(array $payload): array
    {
        $keys = [
            'truck_freight',
            'labour_charge',
            'kat',
            'home_delivery_charge',
            'cash_received',
            'cash_payment',
            'crossing_freight',
            'cartage_dalali_pf',
            'gst_amount',
        ];

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = number_format((float) ($payload[$key] ?? 0), 2, '.', '');
        }

        return $out;
    }

    private function requireStation(Company $company, string $stationId, string $field): Station
    {
        $station = Station::query()
            ->whereKey($stationId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(function ($query) use ($company): void {
                $query->where('owner_user_id', $company->owner_user_id)
                    ->orWhereNull('owner_user_id');
            })
            ->first();

        if ($station === null) {
            throw ValidationException::withMessages([
                $field => 'Station was not found.',
            ]);
        }

        return $station;
    }

    private function requireVehicle(Company $company, Branch $branch, string $vehicleId): Vehicle
    {
        $vehicle = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicleId)
            ->whereNull('deleted_at')
            ->first();

        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle was not found.',
            ]);
        }

        if ($vehicle->branch_id !== null && $vehicle->branch_id !== $branch->id) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is not visible in this branch.',
            ]);
        }

        if (! in_array($vehicle->ownership, [VehicleOwnership::Own, VehicleOwnership::Market], true)) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle ownership is invalid for Partload Challan.',
            ]);
        }

        return $vehicle;
    }

    private function assertDriverParty(Company $company, Branch $branch, string $partyId): void
    {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'driver_party_id' => 'Driver party was not found.',
            ]);
        }

        $isDriver = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $partyId)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->exists();

        if (! $isDriver) {
            throw ValidationException::withMessages([
                'driver_party_id' => 'Selected party is not a Driver.',
            ]);
        }

        if ($party->branch_id !== null && $party->branch_id !== $branch->id) {
            throw ValidationException::withMessages([
                'driver_party_id' => 'Driver is not visible in this branch.',
            ]);
        }
    }

    private function assertEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

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
}
