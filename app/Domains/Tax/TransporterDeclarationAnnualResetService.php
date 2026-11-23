<?php

namespace App\Domains\Tax;

use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\TransporterDeclarationReset;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * April 1 (Asia/Kolkata) annual reset of transporter declarations for the new FY.
 *
 * Idempotent per financial_year via transporter_declaration_resets.
 */
final class TransporterDeclarationAnnualResetService
{
    public const TIMEZONE = 'Asia/Kolkata';

    public function __construct(
        private readonly TransporterTdsCalculationService $calculator = new TransporterTdsCalculationService,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @return array{financial_year: int, reset: bool, transporters_updated: int, marker_id: int|null}
     */
    public function resetForCurrentFinancialYear(?CarbonInterface $now = null): array
    {
        $at = CarbonImmutable::instance($now ?? CarbonImmutable::now(self::TIMEZONE))
            ->timezone(self::TIMEZONE);

        return $this->resetForFinancialYear($this->financialYearFor($at), $at);
    }

    /**
     * @return array{financial_year: int, reset: bool, transporters_updated: int, marker_id: int|null}
     */
    public function resetForFinancialYear(int $financialYear, ?CarbonInterface $now = null): array
    {
        $at = CarbonImmutable::instance($now ?? CarbonImmutable::now(self::TIMEZONE))
            ->timezone(self::TIMEZONE);

        $existing = TransporterDeclarationReset::query()
            ->where('financial_year', $financialYear)
            ->first();

        if ($existing !== null) {
            return [
                'financial_year' => $financialYear,
                'reset' => false,
                'transporters_updated' => 0,
                'marker_id' => $existing->id,
            ];
        }

        return AuditWriter::usingSystemActor('system:tax-declaration-reset', function () use ($financialYear, $at): array {
            return $this->auditedTransaction->run(function () use ($financialYear, $at): array {
                $updated = 0;

                PartyRole::query()
                    ->where('role', 'transporter')
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->chunkById(100, function ($roles) use (&$updated, $at): void {
                        foreach ($roles as $role) {
                            /** @var PartyRole $role */
                            if ($this->resetTransporterRole($role, $at)) {
                                $updated++;
                            }
                        }
                    });

                $marker = TransporterDeclarationReset::query()->create([
                    'financial_year' => $financialYear,
                    'reset_at' => $at,
                ]);

                $this->withPlatformTenant(function () use ($marker, $financialYear, $updated): void {
                    $this->audit->write(
                        AuditAction::Created,
                        TransporterDeclarationReset::class,
                        (string) $marker->id,
                        remark: null,
                        before: null,
                        after: [
                            'financial_year' => $financialYear,
                            'transporters_updated' => $updated,
                            'reset_at' => $marker->reset_at->toIso8601String(),
                        ],
                    );
                });

                return [
                    'financial_year' => $financialYear,
                    'reset' => true,
                    'transporters_updated' => $updated,
                    'marker_id' => $marker->id,
                ];
            });
        });
    }

    public function financialYearFor(CarbonInterface $at): int
    {
        $local = CarbonImmutable::instance($at)->timezone(self::TIMEZONE);

        return $local->month >= 4 ? $local->year : $local->year - 1;
    }

    private function resetTransporterRole(PartyRole $role, CarbonImmutable $at): bool
    {
        $attrs = $role->role_attributes ?? [];
        $before = $role->toArray();

        $attrs['declaration'] = false;

        $party = Party::query()->find($role->party_id);
        $partyAttrs = $party !== null
            ? [
                'pan' => $party->pan,
            ]
            : [];

        $context = $this->calculator->buildContextFromTransporter($partyAttrs, $attrs);
        $override = filter_var($attrs['tds_override'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) ($attrs['tds_override'] ?? false);

        try {
            $result = $this->calculator->calculate(
                $context,
                overrideRate: $override ? (isset($attrs['tds_percentage']) ? (string) $attrs['tds_percentage'] : null) : null,
                overrideRemark: $override ? ($attrs['tds_adjustment_remark'] ?? null) : null,
                allowOverride: $override,
            );
            $attrs['calculated_tds_rate'] = $result->calculatedRate;
            $attrs['applicable_tds_rate'] = $result->applicableRate;
        } catch (TaxDomainException|ValidationException) {
            // Keep declaration reset even if rules/override attrs are incomplete for this transporter.
            unset($attrs['calculated_tds_rate'], $attrs['applicable_tds_rate']);
        }

        $role->role_attributes = $attrs;
        $role->save();

        $this->withCompanyTenant((string) $role->company_id, function () use ($role, $before, $at): void {
            $this->audit->write(
                AuditAction::Updated,
                PartyRole::class,
                $role->id,
                remark: 'Annual transporter declaration reset '.$at->toDateString(),
                before: $before,
                after: $role->fresh()?->toArray(),
            );
        });

        return true;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withCompanyTenant(string $companyId, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(companyId: $companyId, isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
