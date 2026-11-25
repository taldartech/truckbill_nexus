<?php

namespace App\Domains\Accounting;

use App\Domains\Accounting\Enums\AccountingPeriodStatus;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AccountingPeriodService
{
    public function __construct(
        private readonly AuditedTransaction $audited = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    public function ensureCurrentFinancialYear(Company $company, ?CarbonImmutable $asOf = null): AccountingPeriod
    {
        $asOf ??= CarbonImmutable::now('Asia/Kolkata');
        [$label, $start, $end] = $this->indianFinancialYearBounds($asOf);

        $existing = AccountingPeriod::query()
            ->where('company_id', $company->id)
            ->where('label', $label)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return AccountingPeriod::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'label' => $label,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'status' => AccountingPeriodStatus::Open,
        ]);
    }

    public function periodForDate(Company $company, CarbonImmutable|string $date): AccountingPeriod
    {
        $day = $date instanceof CarbonImmutable
            ? $date
            : CarbonImmutable::parse($date, 'Asia/Kolkata');

        $period = AccountingPeriod::query()
            ->where('company_id', $company->id)
            ->whereDate('starts_on', '<=', $day->toDateString())
            ->whereDate('ends_on', '>=', $day->toDateString())
            ->first();

        if ($period !== null) {
            return $period;
        }

        return $this->ensureCurrentFinancialYear($company, $day);
    }

    public function assertOpenForPosting(AccountingPeriod $period): void
    {
        if ($period->status === AccountingPeriodStatus::Closed) {
            throw new AccountingDomainException('Cannot post into a closed accounting period.');
        }
    }

    public function close(AccountingPeriod $period, User $actor, string $remark): AccountingPeriod
    {
        if ($period->status === AccountingPeriodStatus::Closed) {
            return $period;
        }

        $company = Company::query()->findOrFail($period->company_id);

        return $this->audited->run(function () use ($period, $actor, $remark, $company): AccountingPeriod {
            $previous = TenantContextHolder::get();
            TenantContextHolder::inject(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: false,
                isOwnerScope: true,
            ));

            try {
                $before = $period->toArray();
                $period->forceFill([
                    'status' => AccountingPeriodStatus::Closed,
                    'closed_at' => now(),
                    'closed_by_user_id' => $actor->id,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    AccountingPeriod::class,
                    $period->id,
                    remark: $remark !== '' ? $remark : 'Close accounting period',
                    before: $before,
                    after: $period->fresh()?->toArray(),
                );

                return $period->refresh();
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::inject($previous);
                }
            }
        });
    }

    public function reopen(AccountingPeriod $period, User $actor, string $remark): AccountingPeriod
    {
        if ($period->status === AccountingPeriodStatus::Open) {
            return $period;
        }

        $remark = trim($remark);
        if ($remark === '') {
            throw new AccountingDomainException('Reopening a closed period requires a remark.');
        }

        $company = Company::query()->findOrFail($period->company_id);

        return $this->audited->run(function () use ($period, $remark, $company): AccountingPeriod {
            $previous = TenantContextHolder::get();
            TenantContextHolder::inject(new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: false,
                isOwnerScope: true,
            ));

            try {
                $before = $period->toArray();
                $period->forceFill([
                    'status' => AccountingPeriodStatus::Open,
                    'closed_at' => null,
                    'closed_by_user_id' => null,
                ])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    AccountingPeriod::class,
                    $period->id,
                    remark: $remark,
                    before: $before,
                    after: $period->fresh()?->toArray(),
                );

                return $period->refresh();
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::inject($previous);
                }
            }
        });
    }

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function indianFinancialYearBounds(CarbonImmutable $asOf): array
    {
        $year = (int) $asOf->year;
        $month = (int) $asOf->month;
        if ($month < 4) {
            $startYear = $year - 1;
            $endYear = $year;
        } else {
            $startYear = $year;
            $endYear = $year + 1;
        }

        $start = CarbonImmutable::create($startYear, 4, 1, 0, 0, 0, 'Asia/Kolkata');
        $end = CarbonImmutable::create($endYear, 3, 31, 0, 0, 0, 'Asia/Kolkata');
        $label = sprintf('%d-%02d', $startYear, $endYear % 100);

        return [$label, $start, $end];
    }
}
