<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\LorryHireGoodsLine;
use App\Models\LorryHireRevision;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Lorry Hire edit: recalculates effective freight, TDS and payable from the shared builder
 * and records an immutable revision with a delta accounting journal (Rules §37).
 *
 * @phpstan-type RevisionLine array{
 *     lr_goods_row_id: string,
 *     rate_type: string,
 *     rate: numeric-string,
 *     freight_amount: numeric-string
 * }
 * @phpstan-type RevisionCustomAmount array{
 *     id: string|null,
 *     description: string,
 *     amount: numeric-string
 * }
 * @phpstan-type RevisionSide array{
 *     hire_date: string|null,
 *     remarks: string|null,
 *     lines: list<RevisionLine>,
 *     custom_amounts: list<RevisionCustomAmount>,
 *     custom_amounts_total: numeric-string,
 *     freight_total: numeric-string,
 *     tds_amount: numeric-string,
 *     net_payable: numeric-string,
 *     outstanding_amount: numeric-string
 * }
 * @phpstan-type RevisionPlan array{
 *     before: RevisionSide,
 *     after: RevisionSide,
 *     settled_amount: numeric-string,
 *     reserved_voucher_amount: numeric-string,
 *     tds_locked_reason: string|null,
 *     changed: bool
 * }
 */
final class LorryHireRevisionService
{
    /** Legacy TDS submission day: TDS freezes from this day of the month after the Bilty date. */
    private const TDS_SUBMISSION_DAY = 3;

    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly LorryHireCalculationService $calculations = new LorryHireCalculationService,
        private readonly LorryHireGoodsLineBuilder $goodsLines = new LorryHireGoodsLineBuilder,
        private readonly LorryHireCustomAmountService $customAmounts = new LorryHireCustomAmountService,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
        private readonly BrokerAdjustmentService $adjustments = new BrokerAdjustmentService,
    ) {}

    public function canEdit(User $actor, Company $company, Branch $branch, LorryHire $hire): bool
    {
        return $hire->status !== LorryHireStatus::Cancelled
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_EDIT, $company, $branch);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return RevisionPlan
     */
    public function preview(LorryHire $hire, User $actor, array $payload): array
    {
        [$company] = $this->assertEditable($hire, $actor);

        return $this->plan($hire, $company, $payload)[0];
    }

    /**
     * @param  array<string, mixed>  $payload  hire_date, remarks, goods_lines, edit_remark
     */
    public function revise(LorryHire $hire, User $actor, array $payload): LorryHireRevision
    {
        [$company, $branch] = $this->assertEditable($hire, $actor);

        $editRemark = trim((string) ($payload['edit_remark'] ?? ''));
        if ($editRemark === '') {
            throw ValidationException::withMessages(['edit_remark' => 'Enter the reason for editing this Lorry Hire.']);
        }

        return $this->auditedTransaction->run(function () use ($hire, $actor, $company, $branch, $payload, $editRemark): LorryHireRevision {
            $locked = LorryHire::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereKey($hire->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status === LorryHireStatus::Cancelled) {
                throw new BrokerDomainException('Cancelled Lorry Hires cannot be edited.');
            }

            [$plan, $built] = $this->plan($locked, $company, $payload);
            $adjustmentsChanging = $this->adjustments->fixedAdjustmentsDiffer($locked, $payload);
            if (! $plan['changed'] && ! $adjustmentsChanging) {
                throw ValidationException::withMessages(['goods_lines' => 'No changes to save.']);
            }

            $after = $plan['after'];
            $linesByRow = collect($after['lines'])->keyBy('lr_goods_row_id');
            $builtByRow = collect($built)->keyBy('lr_goods_row_id');

            /** @var LorryHireGoodsLine $line */
            foreach ($locked->goodsLines as $line) {
                $revised = $linesByRow->get($line->lr_goods_row_id);
                $builtLine = $builtByRow->get($line->lr_goods_row_id);
                if ($revised === null || $builtLine === null) {
                    continue;
                }

                $line->forceFill([
                    'rate_type' => $builtLine['rate_type']->value,
                    'rate_semantics' => $builtLine['rate_semantics']->value,
                    'rate' => $builtLine['rate'],
                    'is_fixed_amount' => $builtLine['is_fixed_amount'],
                    'freight_amount' => $revised['freight_amount'],
                    'loading_weight' => $builtLine['loading_weight'],
                ])->save();
            }

            $tdsChanged = bccomp($after['tds_amount'], $plan['before']['tds_amount'], 2) !== 0;
            if ($tdsChanged && $locked->tdsSnapshot !== null) {
                $locked->tdsSnapshot->forceFill([
                    'freight_basis' => $this->goodsLines->freightTotal($built),
                    'tds_amount' => $after['tds_amount'],
                ])->save();
            }

            $customTotalAfter = $this->customAmounts->sync(
                $locked,
                $this->customAmountsInput($payload),
            );

            $after['custom_amounts_total'] = $customTotalAfter;
            $after['custom_amounts'] = $this->customAmountRows($locked);

            $locked->forceFill([
                'hire_date' => $after['hire_date'],
                'remarks' => $after['remarks'],
                'freight_total' => $after['freight_total'],
                'tds_amount' => $after['tds_amount'],
            ])->save();

            if ($adjustmentsChanging) {
                if (! LorryHireOperationalDelivery::isDelivered($locked)) {
                    throw ValidationException::withMessages([
                        'fixed_adjustments' => 'Delivery adjustments cannot be changed until the Bilty is delivered.',
                    ]);
                }
                if (is_array($payload['fixed_adjustments'] ?? null)) {
                    $this->adjustments->syncFixedAdjustments(
                        $locked->fresh(['goodsLines.lrGoodsRow', 'adjustments']) ?? $locked,
                        $actor,
                        $payload['fixed_adjustments'],
                    );
                }
            }

            $summaryAfter = $this->calculations->summarize($locked->fresh([
                'customAmounts',
                'adjustments',
                'vouchers',
                'bookBalanceEntries',
            ]));
            $after['net_payable'] = $summaryAfter['net_payable'];
            $after['outstanding_amount'] = $summaryAfter['outstanding_amount'];

            $locked->forceFill([
                'outstanding_amount' => $after['outstanding_amount'],
            ])->save();

            $revision = LorryHireRevision::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'lorry_hire_id' => $locked->id,
                'remark' => $editRemark,
                'before' => $plan['before'],
                'after' => $after,
                'freight_delta' => bcsub($after['freight_total'], $plan['before']['freight_total'], 2),
                'tds_delta' => bcsub($after['tds_amount'], $plan['before']['tds_amount'], 2),
                'created_by_user_id' => $actor->id,
            ]);

            $this->accounting->postLorryHireRevised($revision, $locked, $actor);
            $this->accounting->postLorryHireCustomAmountsRevised(
                $plan['before']['custom_amounts_total'],
                $after['custom_amounts_total'],
                $locked,
                $actor,
            );

            $this->audit->write(
                AuditAction::Updated,
                LorryHire::class,
                $locked->id,
                remark: 'Edit Lorry Hire '.$locked->hire_number.': '.$editRemark,
                before: $plan['before'],
                after: $after,
            );

            return $revision;
        });
    }

    /**
     * Why TDS can no longer change on this hire (legacy canChangeTdsAmount), or null when it can.
     *
     * @param  numeric-string  $outstanding
     */
    public function tdsLockedReason(LorryHire $hire, Company $company, string $outstanding): ?string
    {
        if (bccomp($outstanding, '0.01', 2) <= 0) {
            return 'TDS is locked because this Lorry Hire is fully settled.';
        }

        $lrDate = $hire->lorryReceipt?->lr_date;
        if ($lrDate === null) {
            return null;
        }

        $timezone = is_string($company->timezone) && $company->timezone !== '' ? $company->timezone : 'Asia/Kolkata';
        $submissionDate = CarbonImmutable::parse($lrDate->toDateString(), $timezone)
            ->startOfMonth()
            ->addMonthNoOverflow()
            ->day(self::TDS_SUBMISSION_DAY)
            ->startOfDay();

        if (CarbonImmutable::now($timezone)->greaterThanOrEqualTo($submissionDate)) {
            return 'TDS is locked because the TDS submission date ('.$submissionDate->format('d M Y').') has passed.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: RevisionPlan, 1: list<array{
     *     lr_goods_row_id: string,
     *     rate_type: FreightRateType,
     *     rate: numeric-string,
     *     is_fixed_amount: bool,
     *     freight_amount: numeric-string,
     *     loading_weight: numeric-string,
     *     rate_semantics: FreightRateSemantics
     * }>}
     */
    private function plan(LorryHire $hire, Company $company, array $payload): array
    {
        $hire->load(['lorryReceipt.goodsRows', 'goodsLines.lrGoodsRow', 'adjustments', 'vouchers', 'bookBalanceEntries', 'customAmounts']);
        $lorryReceipt = $hire->lorryReceipt ?? throw new BrokerDomainException('Lorry Hire Bilty is unavailable.');

        $summary = $this->calculations->summarize($hire);
        $input = $this->goodsLinesInput($payload);
        $this->assertSameGoodsRows($hire, $input);

        $built = $this->goodsLines->build($lorryReceipt, $input);
        // TDS basis stays on loading-weight transporter freight (Fixed lines at entered amount).
        $tdsBasis = $this->goodsLines->freightTotal($built);

        $unloading = $this->calculations->latestUnloadingWeight($hire);
        $afterLines = [];
        foreach ($built as $line) {
            $freight = $line['freight_amount'];
            if ($unloading !== null && ! $line['is_fixed_amount']) {
                $freight = $this->calculations->lineFreightAfterUnloading([
                    'rate_type' => $line['rate_type'],
                    'rate' => $line['rate'],
                    'loading_weight' => $line['loading_weight'],
                    'package_count' => $lorryReceipt->goodsRows->firstWhere('id', $line['lr_goods_row_id'])?->package_count,
                    'rate_semantics' => $line['rate_semantics'],
                ], $unloading);
            }

            $afterLines[] = [
                'lr_goods_row_id' => $line['lr_goods_row_id'],
                'rate_type' => $line['rate_type']->value,
                'rate' => $line['rate'],
                'freight_amount' => $freight,
            ];
        }

        $freightAfter = '0.00';
        foreach ($afterLines as $line) {
            $freightAfter = bcadd($freightAfter, $line['freight_amount'], 2);
        }

        $tdsLockedReason = $this->tdsLockedReason($hire, $company, $summary['outstanding_amount']);
        $tdsAfter = $tdsLockedReason === null
            ? $this->calculations->tdsAmountFromFreight($tdsBasis, $this->money($hire->tds_percent, 3))
            : $summary['tds_amount'];

        $customTotalAfter = $this->previewCustomAmountsTotal($hire, $payload);
        $netAfter = $this->calculations->netPayable($freightAfter, $tdsAfter, $customTotalAfter, $hire->adjustments);
        $outstandingAfter = $this->calculations->outstandingAmount($netAfter, $hire->vouchers, $hire->bookBalanceEntries);
        $settled = bcsub($summary['net_payable'], $summary['outstanding_amount'], 2);

        $netReduced = bccomp($netAfter, $summary['net_payable'], 2) < 0;
        $shortfall = bcsub($summary['reserved_voucher_amount'], $outstandingAfter, 2);
        if ($netReduced && bccomp($shortfall, '0', 2) > 0) {
            throw ValidationException::withMessages([
                'goods_lines' => 'This change reduces Net Payable ₹'.$shortfall.' below the amount already paid, adjusted or reserved. Record a recovery through Freight Adjustments instead.',
            ]);
        }

        $before = [
            'hire_date' => $hire->hire_date?->toDateString(),
            'remarks' => $hire->remarks,
            'lines' => array_map(fn (LorryHireGoodsLine $line): array => [
                'lr_goods_row_id' => (string) $line->lr_goods_row_id,
                'rate_type' => $line->rate_type->value,
                'rate' => $this->money($line->rate, 4),
                'freight_amount' => $this->money($line->freight_amount),
            ], array_values($hire->goodsLines->all())),
            'custom_amounts' => $this->customAmountRows($hire),
            'custom_amounts_total' => $this->calculations->customAmountsTotal($hire->customAmounts),
            'freight_total' => $summary['freight_total'],
            'tds_amount' => $summary['tds_amount'],
            'net_payable' => $summary['net_payable'],
            'outstanding_amount' => $summary['outstanding_amount'],
        ];

        $hireDate = filled($payload['hire_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['hire_date'])->toDateString()
            : $before['hire_date'];
        $remarks = filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null;

        $after = [
            'hire_date' => $hireDate,
            'remarks' => $remarks,
            'lines' => $afterLines,
            'custom_amounts' => $this->previewCustomAmountRows($hire, $payload),
            'custom_amounts_total' => $customTotalAfter,
            'freight_total' => $freightAfter,
            'tds_amount' => $tdsAfter,
            'net_payable' => $netAfter,
            'outstanding_amount' => $outstandingAfter,
        ];

        $changed = $this->hasChanges($before, $after)
            || $this->adjustments->fixedAdjustmentsDiffer($hire, $payload);

        return [[
            'before' => $before,
            'after' => $after,
            'settled_amount' => $settled,
            'reserved_voucher_amount' => $summary['reserved_voucher_amount'],
            'tds_locked_reason' => $tdsLockedReason,
            'changed' => $changed,
        ], $built];
    }

    /**
     * @param  RevisionSide  $before
     * @param  RevisionSide  $after
     */
    private function hasChanges(array $before, array $after): bool
    {
        if ($before['hire_date'] !== $after['hire_date']
            || ($before['remarks'] ?? '') !== ($after['remarks'] ?? '')
            || bccomp($before['freight_total'], $after['freight_total'], 2) !== 0
            || bccomp($before['tds_amount'], $after['tds_amount'], 2) !== 0) {
            return true;
        }

        $beforeLines = array_column($before['lines'], null, 'lr_goods_row_id');
        foreach ($after['lines'] as $line) {
            $previous = $beforeLines[$line['lr_goods_row_id']] ?? null;
            if ($previous === null
                || $previous['rate_type'] !== $line['rate_type']
                || bccomp($previous['rate'], $line['rate'], 4) !== 0
                || bccomp($previous['freight_amount'], $line['freight_amount'], 2) !== 0) {
                return true;
            }
        }

        if (bccomp($before['custom_amounts_total'], $after['custom_amounts_total'], 2) !== 0) {
            return true;
        }

        if (json_encode($before['custom_amounts']) !== json_encode($after['custom_amounts'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $input
     */
    private function assertSameGoodsRows(LorryHire $hire, array $input): void
    {
        $existing = $hire->goodsLines->pluck('lr_goods_row_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $submitted = collect($input)->pluck('lr_goods_row_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();

        if ($existing !== $submitted) {
            throw ValidationException::withMessages([
                'goods_lines' => 'Transporter freight lines must match the goods rows of this Lorry Hire.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function goodsLinesInput(array $payload): array
    {
        $lines = $payload['goods_lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function customAmountsInput(array $payload): array
    {
        $rows = $payload['custom_amounts'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return numeric-string
     */
    private function previewCustomAmountsTotal(LorryHire $hire, array $payload): string
    {
        $total = '0.00';
        foreach ($this->customAmountsInput($payload) as $row) {
            if (! is_numeric($row['amount'] ?? null)) {
                continue;
            }
            $total = bcadd($total, $this->money($row['amount']), 2);
        }

        if ($this->customAmountsInput($payload) === []) {
            return $this->calculations->customAmountsTotal($hire->customAmounts);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<RevisionCustomAmount>
     */
    private function previewCustomAmountRows(LorryHire $hire, array $payload): array
    {
        $input = $this->customAmountsInput($payload);
        if ($input === []) {
            return $this->customAmountRows($hire);
        }

        $rows = [];
        foreach ($input as $row) {
            if (! is_numeric($row['amount'] ?? null)) {
                continue;
            }
            $rows[] = [
                'id' => filled($row['id'] ?? null) ? (string) $row['id'] : null,
                'description' => trim((string) ($row['description'] ?? '')),
                'amount' => $this->money($row['amount']),
            ];
        }

        return $rows;
    }

    /**
     * @return list<RevisionCustomAmount>
     */
    private function customAmountRows(LorryHire $hire): array
    {
        $rows = [];
        foreach ($hire->customAmounts as $row) {
            /** @var numeric-string $amount */
            $amount = $this->money($row->amount);
            $rows[] = [
                'id' => $row->id,
                'description' => $row->description,
                'amount' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: Company, 1: Branch}
     */
    private function assertEditable(LorryHire $hire, User $actor): array
    {
        $company = Company::query()->findOrFail($hire->company_id);
        $branch = Branch::query()->findOrFail($hire->branch_id);

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LORRY_HIRE_EDIT, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to edit Broker Lorry Hires.');
        }

        $this->entitlementGate->assertBranchMutationAllowed($company);
        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }

        if ($hire->status === LorryHireStatus::Cancelled) {
            throw new BrokerDomainException('Cancelled Lorry Hires cannot be edited.');
        }

        return [$company, $branch];
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, int $scale = 2): string
    {
        return number_format((float) ($value ?? 0), $scale, '.', '');
    }
}
