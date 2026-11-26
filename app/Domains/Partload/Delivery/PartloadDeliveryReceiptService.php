<?php

namespace App\Domains\Partload\Delivery;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
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
use App\Models\PartloadDeliveryReceipt;
use App\Models\PartloadInwardLine;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One Delivery Receipt per GR / Partload Bilty (Rules §12). Marks LR Delivered.
 */
final class PartloadDeliveryReceiptService
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
     * @param  array{
     *     lorry_receipt_id: string,
     *     partload_inward_line_id?: string|null,
     *     dr_date?: string|null,
     *     payment_mode: string,
     *     payment_due_from_party_id?: string|null,
     *     bank_cash_account_id?: string|null,
     *     freight?: numeric-string|float|int|null,
     *     handling_charge?: numeric-string|float|int|null,
     *     delivery_charge?: numeric-string|float|int|null,
     *     stationery_charge?: numeric-string|float|int|null,
     *     demurrage?: numeric-string|float|int|null,
     *     cartage_charge?: numeric-string|float|int|null,
     *     other_charge?: numeric-string|float|int|null,
     *     gst_amount?: numeric-string|float|int|null,
     *     less_amount?: numeric-string|float|int|null,
     *     remark?: string|null,
     * }  $payload
     */
    public function create(Company $company, Branch $branch, User $actor, array $payload): PartloadDeliveryReceipt
    {
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_DR_CREATE);
        $this->ensureTenantMatches($company, $branch);

        $lr = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey((string) $payload['lorry_receipt_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($lr === null) {
            throw ValidationException::withMessages([
                'lorry_receipt_id' => 'Bilty was not found in this branch.',
            ]);
        }

        if ($lr->status === LrStatus::Delivered || $lr->status === LrStatus::Invoiced) {
            throw new PartloadConflictException('Bilty is already delivered or invoiced.');
        }

        if ($lr->status === LrStatus::Cancelled) {
            throw new PartloadConflictException('Cancelled Bilties cannot receive a Delivery Receipt.');
        }

        $existing = PartloadDeliveryReceipt::query()
            ->where('company_id', $company->id)
            ->where('lorry_receipt_id', $lr->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($existing) {
            throw new PartloadConflictException('A Delivery Receipt already exists for this Bilty.');
        }

        $mode = PartloadDeliveryPaymentMode::from((string) $payload['payment_mode']);
        if ($mode === PartloadDeliveryPaymentMode::Due && ! filled($payload['payment_due_from_party_id'] ?? null)) {
            throw ValidationException::withMessages([
                'payment_due_from_party_id' => 'Payment Due From is required for Due mode.',
            ]);
        }

        if (in_array($mode, [PartloadDeliveryPaymentMode::Cash, PartloadDeliveryPaymentMode::Bank], true)
            && ! filled($payload['bank_cash_account_id'] ?? null)) {
            throw ValidationException::withMessages([
                'bank_cash_account_id' => 'Cash or Bank account is required for this payment mode.',
            ]);
        }

        $inwardLine = null;
        if (filled($payload['partload_inward_line_id'] ?? null)) {
            $inwardLine = PartloadInwardLine::query()
                ->where('company_id', $company->id)
                ->whereKey((string) $payload['partload_inward_line_id'])
                ->where('lorry_receipt_id', $lr->id)
                ->whereNull('deleted_at')
                ->first();

            if ($inwardLine === null) {
                throw ValidationException::withMessages([
                    'partload_inward_line_id' => 'Inward line was not found for this Bilty.',
                ]);
            }

            if ($inwardLine->disposition !== PartloadInwardDisposition::Delivery) {
                throw new PartloadConflictException('Only Delivery-disposition Inward lines can create a DR.');
            }
        }

        $charges = $this->normalizeCharges($payload, $lr);

        try {
            return $this->auditedTransaction->run(function () use (
                $company,
                $branch,
                $actor,
                $payload,
                $lr,
                $mode,
                $inwardLine,
                $charges,
            ): PartloadDeliveryReceipt {
                return $this->withTenant($company, $branch, function () use (
                    $company,
                    $branch,
                    $actor,
                    $payload,
                    $lr,
                    $mode,
                    $inwardLine,
                    $charges,
                ): PartloadDeliveryReceipt {
                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::PartloadDeliveryReceipt,
                        scopeType: DocumentNumberScopeType::Branch,
                        branchId: $branch->id,
                    );

                    $dr = PartloadDeliveryReceipt::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'dr_number' => $allocation['number'],
                        'dr_date' => filled($payload['dr_date'] ?? null)
                            ? CarbonImmutable::parse((string) $payload['dr_date'])->toDateString()
                            : now()->toDateString(),
                        'lorry_receipt_id' => $lr->id,
                        'partload_inward_line_id' => $inwardLine?->id,
                        'consignee_party_id' => $lr->consignee_party_id,
                        'payment_mode' => $mode,
                        'payment_due_from_party_id' => filled($payload['payment_due_from_party_id'] ?? null)
                            ? (string) $payload['payment_due_from_party_id']
                            : null,
                        'bank_cash_account_id' => filled($payload['bank_cash_account_id'] ?? null)
                            ? (string) $payload['bank_cash_account_id']
                            : null,
                        ...$charges,
                        'status' => 'posted',
                        'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                        'created_by_user_id' => $actor->id,
                    ]);

                    $lr->forceFill([
                        'status' => LrStatus::Delivered,
                        'delivered_at' => now(),
                    ])->save();

                    $this->audit->write(
                        AuditAction::Created,
                        PartloadDeliveryReceipt::class,
                        $dr->id,
                        remark: 'Partload Delivery Receipt created',
                        before: null,
                        after: [
                            'dr_number' => $dr->dr_number,
                            'lorry_receipt_id' => $lr->id,
                            'final_amount' => $dr->final_amount,
                        ],
                    );

                    $posted = $dr->fresh(['lorryReceipt']) ?? $dr;
                    $this->accounting->postPartloadDeliveryReceipt($posted, $actor);

                    return $posted;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new PartloadConflictException(
                'A Delivery Receipt already exists for this Bilty.',
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     freight: string,
     *     handling_charge: string,
     *     delivery_charge: string,
     *     stationery_charge: string,
     *     demurrage: string,
     *     cartage_charge: string,
     *     other_charge: string,
     *     gst_amount: string,
     *     less_amount: string,
     *     final_amount: string
     * }
     */
    private function normalizeCharges(array $payload, LorryReceipt $lr): array
    {
        $freight = number_format((float) ($payload['freight'] ?? $lr->freight_total), 2, '.', '');
        $handling = number_format((float) ($payload['handling_charge'] ?? 0), 2, '.', '');
        $delivery = number_format((float) ($payload['delivery_charge'] ?? 0), 2, '.', '');
        $stationery = number_format((float) ($payload['stationery_charge'] ?? 0), 2, '.', '');
        $demurrage = number_format((float) ($payload['demurrage'] ?? 0), 2, '.', '');
        $cartage = number_format((float) ($payload['cartage_charge'] ?? 0), 2, '.', '');
        $other = number_format((float) ($payload['other_charge'] ?? 0), 2, '.', '');
        $gst = number_format((float) ($payload['gst_amount'] ?? 0), 2, '.', '');
        $less = number_format((float) ($payload['less_amount'] ?? 0), 2, '.', '');

        $subtotal = (float) $freight + (float) $handling + (float) $delivery + (float) $stationery
            + (float) $demurrage + (float) $cartage + (float) $other + (float) $gst - (float) $less;

        return [
            'freight' => $freight,
            'handling_charge' => $handling,
            'delivery_charge' => $delivery,
            'stationery_charge' => $stationery,
            'demurrage' => $demurrage,
            'cartage_charge' => $cartage,
            'other_charge' => $other,
            'gst_amount' => $gst,
            'less_amount' => $less,
            'final_amount' => number_format($subtotal, 2, '.', ''),
        ];
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
