<?php

namespace App\Domains\Broker\Delivery;

use App\Domains\Broker\Enums\BrokerDeliveryStatus;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\BrokerDelivery;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Broker delivery memo/POD and LR delivery recording (PRD §13).
 */
final class BrokerDeliveryService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentAttachmentService $documents = new DocumentAttachmentService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, LorryReceipt>
     */
    public function searchEligible(
        Company $company,
        Branch $branch,
        ?string $billingCustomerId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('workflow_context', LrWorkflowContext::Broker->value)
            ->where('status', LrStatus::InProgress->value)
            ->whereNull('delivered_at')
            ->whereNull('deleted_at')
            ->whereHas('lorryHire', function ($query): void {
                $query->whereNull('deleted_at')
                    ->where('status', '!=', LorryHireStatus::Cancelled->value);
            })
            ->with([
                'billingCustomer',
                'lorryHire',
                'delivery',
                'fromStation',
                'toStation',
            ])
            ->orderByDesc('lr_date')
            ->orderByDesc('created_at');

        if (filled($billingCustomerId)) {
            $builder->where('billing_customer_party_id', $billingCustomerId);
        }

        return $builder->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, BrokerDelivery>
     */
    public function searchRecent(Company $company, Branch $branch, int $perPage = 15): LengthAwarePaginator
    {
        $this->ensureTenantMatches($company, $branch);

        return BrokerDelivery::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['lorryReceipt.billingCustomer', 'lorryHire'])
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{
     *     lorry_receipt_ids: list<string>,
     *     memo_number: string,
     *     pod_number?: string|null,
     *     remarks?: string|null
     * }  $payload
     * @return Collection<int, BrokerDelivery>
     */
    public function assignMemoPod(Company $company, Branch $branch, User $actor, array $payload): Collection
    {
        $this->ensureTenantMatches($company, $branch);
        $this->assertCanManage($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $ids = array_values(array_unique(array_map('strval', $payload['lorry_receipt_ids'])));
        if ($ids === []) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Select at least one Lorry Receipt.',
            ]);
        }

        $memoNumber = trim($payload['memo_number']);
        if ($memoNumber === '') {
            throw ValidationException::withMessages([
                'memo_number' => 'Memo number is required.',
            ]);
        }

        /** @var Collection<int, LorryReceipt> $receipts */
        $receipts = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->with(['lorryHire', 'delivery'])
            ->get();

        if ($receipts->count() !== count($ids)) {
            throw new BrokerDomainException('One or more Lorry Receipts were not found.');
        }

        $this->assertSameBillingCustomer($receipts);

        foreach ($receipts as $receipt) {
            $this->assertEligibleForDelivery($receipt);
        }

        $podNumber = filled($payload['pod_number'] ?? null) ? trim((string) $payload['pod_number']) : null;
        $remarks = filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null;

        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $receipts, $memoNumber, $podNumber, $remarks): Collection {
            return $this->withTenant($company, $branch, function () use ($company, $branch, $actor, $receipts, $memoNumber, $podNumber, $remarks): Collection {
                $saved = collect();

                foreach ($receipts as $receipt) {
                    $hire = $receipt->lorryHire;
                    if ($hire === null) {
                        throw new BrokerConflictException('Each selected LR must have an active Lorry Hire.');
                    }

                    $delivery = $receipt->delivery;
                    if ($delivery === null) {
                        $delivery = BrokerDelivery::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'branch_id' => $branch->id,
                            'lorry_receipt_id' => $receipt->id,
                            'lorry_hire_id' => $hire->id,
                            'status' => BrokerDeliveryStatus::MemoAssigned->value,
                            'memo_number' => $memoNumber,
                            'pod_number' => $podNumber,
                            'remarks' => $remarks,
                            'created_by_user_id' => $actor->id,
                        ]);
                    } else {
                        if ($delivery->status === BrokerDeliveryStatus::Delivered) {
                            throw new BrokerConflictException('Cannot reassign memo for a delivered LR '.$receipt->lr_number.'.');
                        }

                        $before = $delivery->only(['memo_number', 'pod_number', 'status']);
                        $delivery->forceFill([
                            'memo_number' => $memoNumber,
                            'pod_number' => $podNumber,
                            'status' => BrokerDeliveryStatus::MemoAssigned->value,
                            'remarks' => $remarks,
                            'lorry_hire_id' => $hire->id,
                        ])->save();

                        $this->audit->write(
                            AuditAction::Updated,
                            BrokerDelivery::class,
                            $delivery->id,
                            remark: 'Assign delivery memo '.$memoNumber,
                            before: $before,
                            after: $delivery->only(['memo_number', 'pod_number', 'status']),
                        );
                    }

                    if ($delivery->wasRecentlyCreated) {
                        $this->audit->write(
                            AuditAction::Created,
                            BrokerDelivery::class,
                            $delivery->id,
                            remark: 'Assign delivery memo '.$memoNumber.' for LR '.$receipt->lr_number,
                            before: null,
                            after: $delivery->toArray(),
                        );
                    }

                    $saved->push($delivery->fresh() ?? $delivery);
                }

                return $saved;
            });
        });
    }

    /**
     * @param  array{
     *     delivery_date: string,
     *     remarks?: string|null
     * }  $payload
     */
    public function recordDelivery(
        LorryReceipt $lorryReceipt,
        User $actor,
        array $payload,
    ): BrokerDelivery {
        $company = Company::query()->findOrFail($lorryReceipt->company_id);
        $branch = Branch::query()->findOrFail($lorryReceipt->branch_id);

        $this->ensureTenantMatches($company, $branch);
        $this->assertBelongsToBranch($lorryReceipt, $company, $branch);
        $this->assertCanManage($actor, $company, $branch);
        $this->assertBrokerEntitled($company);
        $this->assertEligibleForDelivery($lorryReceipt);

        $deliveryDate = CarbonImmutable::parse($payload['delivery_date'])->startOfDay();

        $remarks = filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null;

        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $lorryReceipt, $deliveryDate, $remarks): BrokerDelivery {
            return $this->withTenant($company, $branch, function () use ($company, $branch, $actor, $lorryReceipt, $deliveryDate, $remarks): BrokerDelivery {
                $hire = $lorryReceipt->lorryHire;
                if ($hire === null || $hire->status === LorryHireStatus::Cancelled) {
                    throw new BrokerConflictException('Lorry Hire is required to record delivery.');
                }

                $delivery = BrokerDelivery::query()
                    ->where('company_id', $company->id)
                    ->where('lorry_receipt_id', $lorryReceipt->id)
                    ->whereNull('deleted_at')
                    ->first();

                if ($delivery === null) {
                    $delivery = BrokerDelivery::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'lorry_receipt_id' => $lorryReceipt->id,
                        'lorry_hire_id' => $hire->id,
                        'status' => BrokerDeliveryStatus::Delivered->value,
                        'delivery_date' => $deliveryDate,
                        'remarks' => $remarks,
                        'created_by_user_id' => $actor->id,
                    ]);
                } else {
                    $delivery->forceFill([
                        'status' => BrokerDeliveryStatus::Delivered->value,
                        'delivery_date' => $deliveryDate,
                        'remarks' => $remarks ?? $delivery->remarks,
                        'lorry_hire_id' => $hire->id,
                    ])->save();
                }

                $lrBefore = [
                    'status' => $lorryReceipt->status->value,
                    'delivered_at' => $lorryReceipt->delivered_at?->toIso8601String(),
                ];

                $lorryReceipt->forceFill([
                    'status' => LrStatus::Delivered,
                    'delivered_at' => $deliveryDate->setTimezone('UTC'),
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    LorryReceipt::class,
                    $lorryReceipt->id,
                    remark: 'Record Broker LR delivery',
                    before: $lrBefore,
                    after: [
                        'status' => LrStatus::Delivered->value,
                        'delivered_at' => $lorryReceipt->delivered_at?->toIso8601String(),
                    ],
                );

                $this->audit->write(
                    AuditAction::Updated,
                    BrokerDelivery::class,
                    $delivery->id,
                    remark: 'Record delivery for LR '.$lorryReceipt->lr_number,
                    before: null,
                    after: $delivery->fresh()?->toArray(),
                );

                return $delivery->fresh() ?? $delivery;
            });
        });
    }

    public function attachPodDocument(
        BrokerDelivery $delivery,
        User $actor,
        UploadedFile $upload,
        ?string $label = null,
    ): void {
        $company = Company::query()->findOrFail($delivery->company_id);
        $branch = Branch::query()->findOrFail($delivery->branch_id);

        $this->ensureTenantMatches($company, $branch);
        $this->assertCanManage($actor, $company, $branch);

        $status = $delivery->status;
        if ($status !== BrokerDeliveryStatus::Delivered && $status !== BrokerDeliveryStatus::MemoAssigned) {
            throw new BrokerConflictException('POD can only attach to memo-assigned or delivered records.');
        }

        $this->documents->uploadAndAttach(
            $actor,
            $company,
            $branch,
            'broker_delivery',
            $delivery->id,
            $upload,
            documentType: 'pod',
            label: $label,
            remark: 'Attach POD for delivery '.$delivery->id,
        );
    }

    /**
     * @param  Collection<int, LorryReceipt>  $receipts
     */
    private function assertSameBillingCustomer(Collection $receipts): void
    {
        $billingIds = $receipts
            ->map(fn (LorryReceipt $receipt): ?string => $receipt->billing_customer_party_id)
            ->unique()
            ->values();

        if ($billingIds->contains(null)) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Every selected LR must have a billing customer.',
            ]);
        }

        if ($billingIds->count() > 1) {
            throw new BrokerConflictException('Bulk memo assignment requires the same billing customer on every LR.');
        }
    }

    private function assertEligibleForDelivery(LorryReceipt $receipt): void
    {
        if ($receipt->workflow_context !== LrWorkflowContext::Broker) {
            throw new BrokerConflictException('Only Broker LRs are eligible for delivery.');
        }

        if ($receipt->status === LrStatus::Delivered || $receipt->delivered_at !== null) {
            throw new BrokerConflictException('Lorry Receipt is already delivered.');
        }

        if ($receipt->status === LrStatus::Invoiced) {
            throw new BrokerConflictException('Invoiced LRs cannot be re-delivered.');
        }

        if ($receipt->status === LrStatus::Cancelled) {
            throw new BrokerConflictException('Cancelled LRs cannot be delivered.');
        }

        if ($receipt->status !== LrStatus::InProgress) {
            throw new BrokerConflictException('Lorry Receipt must be In Progress (with Lorry Hire) before delivery.');
        }
    }

    private function assertBelongsToBranch(LorryReceipt $receipt, Company $company, Branch $branch): void
    {
        if ($receipt->company_id !== $company->id || $receipt->branch_id !== $branch->id) {
            throw new BrokerDomainException('Lorry Receipt does not belong to branch.');
        }
    }

    private function assertCanManage(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_MANAGE, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to manage Broker deliveries.');
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
