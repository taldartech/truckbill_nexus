<?php

namespace App\Domains\Broker\LinkedBilty;

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LinkedBiltyGroup;
use App\Models\LinkedBiltyGroupMember;
use App\Models\LinkedBiltyGroupPaymentIdempotency;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\TransporterVoucher;
use App\Models\User;
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
 * Linked Bilty groups — minimum two Broker LRs per group; proportional group payment (Rules §11).
 */
final class LinkedBiltyGroupService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
        private readonly LinkedGroupPaymentAllocator $allocator = new LinkedGroupPaymentAllocator,
        private readonly TransporterVoucherService $vouchers = new TransporterVoucherService,
        private readonly LorryHireService $lorryHires = new LorryHireService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LinkedBiltyGroup>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = LinkedBiltyGroup::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['vehicle', 'members.lorryReceipt'])
            ->withCount('members')
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }

        return $builder->paginate($perPage);
    }

    public function findForBranch(Company $company, Branch $branch, string $groupId): LinkedBiltyGroup
    {
        $this->ensureTenantMatches($company, $branch);

        $group = LinkedBiltyGroup::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($groupId)
            ->whereNull('deleted_at')
            ->with([
                'vehicle',
                'payee',
                'members' => fn ($query) => $query->whereNull('deleted_at'),
                'members.lorryReceipt',
                'members.lorryHire.lorryReceipt',
                'members.lorryHire.vehicle',
                'members.lorryHire.transporter',
            ])
            ->first();

        if ($group === null) {
            throw new BrokerDomainException('Linked Bilty group not found.');
        }

        return $group;
    }

    /**
     * Broker LRs eligible for a new group (same vehicle, not in an open group).
     *
     * @return Collection<int, LorryReceipt>
     */
    public function listEligibleLorryReceipts(Company $company, Branch $branch, ?string $vehicleId = null): Collection
    {
        $this->ensureTenantMatches($company, $branch);

        $openMemberReceiptIds = LinkedBiltyGroupMember::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('group', function (Builder $query): void {
                $query->where('status', 'open')->whereNull('deleted_at');
            })
            ->pluck('lorry_receipt_id');

        $builder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::Broker->value)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $openMemberReceiptIds)
            ->with(['vehicle', 'billingCustomer'])
            ->orderByDesc('created_at');

        if (filled($vehicleId)) {
            $builder->where('vehicle_id', $vehicleId);
        }

        return $builder->limit(100)->get();
    }

    /**
     * @param  array{
     *     group_date?: string|null,
     *     vehicle_id: string,
     *     payee_party_id?: string|null,
     *     driver_name?: string|null,
     *     reference?: string|null,
     *     remarks?: string|null,
     *     lorry_receipt_ids: list<string>,
     * }  $payload
     */
    public function createGroup(User $actor, Company $company, Branch $branch, array $payload): LinkedBiltyGroup
    {
        $this->assertCanCreate($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $receiptIds = array_values(array_unique(array_map('strval', $payload['lorry_receipt_ids'])));
        if (count($receiptIds) < 2) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'At least two Broker Lorry Receipts are required.',
            ]);
        }

        $vehicleId = (string) $payload['vehicle_id'];
        if ($vehicleId === '') {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is required.',
            ]);
        }

        $receipts = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $receiptIds)
            ->whereNull('deleted_at')
            ->get();

        if ($receipts->count() !== count($receiptIds)) {
            throw new BrokerDomainException('One or more Lorry Receipts were not found.');
        }

        foreach ($receipts as $receipt) {
            if ($receipt->workflow_context !== LrWorkflowContext::Broker) {
                throw new BrokerConflictException('Linked Bilty groups accept Broker workflow Lorry Receipts only.');
            }
            if ((string) $receipt->vehicle_id !== $vehicleId) {
                throw new BrokerConflictException('All grouped Lorry Receipts must share the same vehicle.');
            }
        }

        $this->assertReceiptsNotInOpenGroup($company->id, $receiptIds);

        $groupDate = filled($payload['group_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['group_date'])->startOfDay()
            : CarbonImmutable::now($this->companyTimezone($company))->startOfDay();

        try {
            return $this->auditedTransaction->run(function () use (
                $actor,
                $company,
                $branch,
                $payload,
                $receipts,
                $vehicleId,
                $groupDate,
            ): LinkedBiltyGroup {
                return $this->withTenant($company, $branch, function () use (
                    $actor,
                    $company,
                    $branch,
                    $payload,
                    $receipts,
                    $vehicleId,
                    $groupDate,
                ): LinkedBiltyGroup {
                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::LinkedBiltyGroup,
                        scopeType: DocumentNumberScopeType::Branch,
                        branchId: $branch->id,
                    );

                    $group = LinkedBiltyGroup::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'group_number' => $allocation['number'],
                        'group_date' => $groupDate,
                        'vehicle_id' => $vehicleId,
                        'payee_party_id' => filled($payload['payee_party_id'] ?? null)
                            ? (string) $payload['payee_party_id']
                            : null,
                        'driver_name' => filled($payload['driver_name'] ?? null)
                            ? trim((string) $payload['driver_name'])
                            : null,
                        'reference' => filled($payload['reference'] ?? null)
                            ? trim((string) $payload['reference'])
                            : null,
                        'status' => 'open',
                        'total_amount' => '0.00',
                        'remarks' => filled($payload['remarks'] ?? null)
                            ? trim((string) $payload['remarks'])
                            : null,
                        'created_by_user_id' => $actor->id,
                    ]);

                    foreach ($receipts as $receipt) {
                        $hire = $this->lorryHires->hireForLorryReceipt($receipt);

                        LinkedBiltyGroupMember::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'linked_bilty_group_id' => $group->id,
                            'lorry_receipt_id' => $receipt->id,
                            'lorry_hire_id' => $hire?->id,
                            'share_amount' => '0.00',
                        ]);
                    }

                    $this->audit->write(
                        AuditAction::Created,
                        LinkedBiltyGroup::class,
                        $group->id,
                        remark: 'Create Linked Bilty group '.$group->group_number,
                        before: null,
                        after: ['member_count' => $receipts->count()],
                    );

                    return $this->findForBranch($company, $branch, $group->id);
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BrokerConflictException('Linked Bilty group number conflict.', previous: $e);
        }
    }

    /**
     * @return list<array{
     *     member_id: string,
     *     lorry_receipt_id: string,
     *     lorry_hire_id: string|null,
     *     hire_number: string|null,
     *     lr_number: string|null,
     *     allocatable_amount: string,
     *     has_hire: bool
     * }>
     */
    public function ensureLorryHires(LinkedBiltyGroup $group): array
    {
        $group->loadMissing(['members.lorryReceipt', 'members.lorryHire']);

        $rows = [];
        foreach ($group->members as $member) {
            $hire = $member->lorryHire;
            if ($hire === null) {
                $hire = $this->lorryHires->hireForLorryReceipt($member->lorryReceipt);
                if ($hire !== null) {
                    $member->forceFill(['lorry_hire_id' => $hire->id])->save();
                }
            }

            $rows[] = [
                'member_id' => $member->id,
                'lorry_receipt_id' => $member->lorry_receipt_id,
                'lorry_hire_id' => $hire?->id,
                'hire_number' => $hire?->hire_number,
                'lr_number' => $member->lorryReceipt?->lr_number,
                'allocatable_amount' => $hire !== null ? $this->vouchers->allocatableAmount($hire) : '0.00',
                'has_hire' => $hire !== null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     group_id: string,
     *     payment_amount: string,
     *     total_eligible: string,
     *     lines: list<array{
     *         lorry_hire_id: string,
     *         member_id: string,
     *         lr_number: string|null,
     *         hire_number: string|null,
     *         eligible_outstanding: string,
     *         allocated_amount: string
     *     }>
     * }
     */
    public function previewGroupPayment(
        LinkedBiltyGroup $group,
        string|float|int $amount,
        VoucherPaymentType|string $paymentType,
        VoucherPaymentMode|string $paymentMode,
    ): array {
        unset($paymentType, $paymentMode);

        $eligible = $this->resolveEligibleHires($group);
        $payment = $this->positiveMoney($amount, 'amount');

        $byHireId = [];
        foreach ($eligible['lines'] as $line) {
            /** @var numeric-string $eligibleOutstanding */
            $eligibleOutstanding = $this->money($line['eligible_outstanding']);
            $byHireId[$line['lorry_hire_id']] = $eligibleOutstanding;
        }

        $allocated = $this->allocator->allocate($byHireId, $payment);

        $previewLines = [];
        foreach ($eligible['lines'] as $line) {
            $hireId = $line['lorry_hire_id'];
            $previewLines[] = [
                'lorry_hire_id' => $hireId,
                'member_id' => $line['member_id'],
                'lr_number' => $line['lr_number'],
                'hire_number' => $line['hire_number'],
                'eligible_outstanding' => $line['eligible_outstanding'],
                'allocated_amount' => $allocated[$hireId] ?? '0.00',
            ];
        }

        return [
            'group_id' => $group->id,
            'payment_amount' => $payment,
            'total_eligible' => $eligible['total_eligible'],
            'lines' => $previewLines,
        ];
    }

    /**
     * @param  array{
     *     amount: string|float|int,
     *     payment_type: string,
     *     payment_mode: string,
     *     voucher_date?: string|null,
     *     bank_cash_account_id?: string|null,
     *     cash_reference?: string|null,
     *     pump_reference?: string|null,
     *     remark?: string|null,
     *     idempotency_key?: string|null,
     * }  $payload
     * @return list<TransporterVoucher>
     */
    public function payGroup(LinkedBiltyGroup $group, User $actor, array $payload): array
    {
        $company = Company::query()->findOrFail($group->company_id);
        $branch = Branch::query()->findOrFail($group->branch_id);

        $this->assertCanPay($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $idempotencyKey = filled($payload['idempotency_key'] ?? null)
            ? trim((string) $payload['idempotency_key'])
            : null;

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $cached = LinkedBiltyGroupPaymentIdempotency::query()
                ->where('company_id', $company->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($cached !== null) {
                $cachedVouchers = TransporterVoucher::query()
                    ->where('company_id', $company->id)
                    ->whereIn('id', $cached->transporter_voucher_ids ?? [])
                    ->get()
                    ->all();

                return array_values($cachedVouchers);
            }
        }

        $payment = $this->positiveMoney($payload['amount'], 'amount');
        $preview = $this->previewGroupPayment(
            $group,
            $payment,
            (string) $payload['payment_type'],
            (string) $payload['payment_mode'],
        );

        $voucherPayloadBase = [
            'payment_type' => (string) $payload['payment_type'],
            'payment_mode' => (string) $payload['payment_mode'],
            'voucher_date' => $payload['voucher_date'] ?? null,
            'bank_cash_account_id' => $payload['bank_cash_account_id'] ?? null,
            'cash_reference' => $payload['cash_reference'] ?? null,
            'pump_reference' => $payload['pump_reference'] ?? null,
            'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
            'linked_bilty_group_id' => $group->id,
        ];

        return $this->auditedTransaction->run(function () use (
            $group,
            $actor,
            $company,
            $branch,
            $preview,
            $voucherPayloadBase,
            $idempotencyKey,
        ): array {
            return $this->withTenant($company, $branch, function () use (
                $group,
                $actor,
                $company,
                $preview,
                $voucherPayloadBase,
                $idempotencyKey,
            ): array {
                /** @var LinkedBiltyGroup|null $locked */
                $locked = LinkedBiltyGroup::query()
                    ->whereKey($group->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    throw new BrokerDomainException('Linked Bilty group not found.');
                }

                if ($locked->status !== 'open') {
                    throw new BrokerConflictException('Linked Bilty group is not open for payment.');
                }

                $created = [];
                foreach ($preview['lines'] as $line) {
                    $allocated = $this->money($line['allocated_amount']);
                    if (bccomp($allocated, '0', 2) !== 1) {
                        continue;
                    }

                    $hire = LorryHire::query()
                        ->whereKey($line['lorry_hire_id'])
                        ->whereNull('deleted_at')
                        ->firstOrFail();

                    $voucher = $this->vouchers->createForLorryHire($hire, $actor, array_merge(
                        $voucherPayloadBase,
                        ['amount' => $allocated],
                    ));
                    $created[] = $voucher;

                    LinkedBiltyGroupMember::query()
                        ->whereKey($line['member_id'])
                        ->update(['share_amount' => $allocated]);
                }

                if ($created === []) {
                    throw new BrokerDomainException('Group payment produced no voucher lines.');
                }

                $this->audit->write(
                    AuditAction::Updated,
                    LinkedBiltyGroup::class,
                    $locked->id,
                    remark: 'Linked Bilty proportional group payment',
                    before: null,
                    after: [
                        'payment_amount' => $preview['payment_amount'],
                        'voucher_count' => count($created),
                    ],
                );

                if (filled($idempotencyKey)) {
                    try {
                        LinkedBiltyGroupPaymentIdempotency::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'linked_bilty_group_id' => $locked->id,
                            'idempotency_key' => $idempotencyKey,
                            'transporter_voucher_ids' => collect($created)->pluck('id')->values()->all(),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        throw new BrokerConflictException('Duplicate group payment idempotency key.');
                    }
                }

                return $created;
            });
        });
    }

    /**
     * @return array{
     *     lines: list<array{
     *         member_id: string,
     *         lorry_hire_id: string,
     *         lr_number: string|null,
     *         hire_number: string|null,
     *         eligible_outstanding: string
     *     }>,
     *     total_eligible: string
     * }
     */
    private function resolveEligibleHires(LinkedBiltyGroup $group): array
    {
        $group->loadMissing(['members.lorryReceipt', 'members.lorryHire']);

        $lines = [];
        $totalEligible = '0.00';

        foreach ($group->members as $member) {
            $hire = $member->lorryHire ?? $this->lorryHires->hireForLorryReceipt($member->lorryReceipt);
            if ($hire === null) {
                throw new BrokerConflictException(
                    'Each Linked Bilty member requires a Lorry Hire before group payment.',
                );
            }

            $eligible = $this->vouchers->allocatableAmount($hire);
            if (bccomp($eligible, '0', 2) !== 1) {
                continue;
            }

            $lines[] = [
                'member_id' => $member->id,
                'lorry_hire_id' => $hire->id,
                'lr_number' => $member->lorryReceipt?->lr_number,
                'hire_number' => $hire->hire_number,
                'eligible_outstanding' => $eligible,
            ];
            $totalEligible = bcadd($totalEligible, $eligible, 2);
        }

        if ($lines === []) {
            throw new BrokerDomainException('No eligible outstanding balance for group payment.');
        }

        return [
            'lines' => $lines,
            'total_eligible' => $totalEligible,
        ];
    }

    /**
     * @param  list<string>  $receiptIds
     */
    private function assertReceiptsNotInOpenGroup(string $companyId, array $receiptIds): void
    {
        $conflict = LinkedBiltyGroupMember::query()
            ->where('company_id', $companyId)
            ->whereIn('lorry_receipt_id', $receiptIds)
            ->whereNull('deleted_at')
            ->whereHas('group', function (Builder $query): void {
                $query->where('status', 'open')->whereNull('deleted_at');
            })
            ->exists();

        if ($conflict) {
            throw new BrokerConflictException('One or more Lorry Receipts are already in an open Linked Bilty group.');
        }
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LINKED_BILTY_CREATE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to create Linked Bilty groups.');
        }
    }

    private function assertCanPay(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LINKED_BILTY_PAY, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to pay Linked Bilty groups.');
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_CREATE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to create transporter vouchers.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new BrokerDomainException('Branch does not belong to company.');
        }
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->timezone ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }

    /**
     * @return numeric-string
     */
    private function positiveMoney(mixed $value, string $field): string
    {
        $money = $this->money($value);
        if (bccomp($money, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                $field => 'Amount must be greater than zero.',
            ]);
        }

        return $money;
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
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
