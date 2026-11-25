<?php

namespace App\Domains\Tax;

use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Models\GstRate;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centrally managed GST rates (Rules §5–§6).
 *
 * Historical versions remain immutable references for transaction snapshots.
 * Not a production statutory-compliance claim — external legal validation Pending.
 */
final class GstRateService
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createVersion(User $actor, array $attributes, string $remark = 'Create GST rate version'): GstRate
    {
        $this->assertPlatformMutate($actor);

        $rate = $attributes['rate_percent'] ?? null;
        if ($rate === null || $rate === '') {
            throw ValidationException::withMessages(['rate_percent' => 'GST rate (%) is required.']);
        }

        $rateNormalized = $this->normalizeRatePercent($rate);
        $code = strtoupper(trim((string) ($attributes['code'] ?? '')));
        if ($code === '') {
            $code = $this->codeFromRatePercent($rateNormalized);
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            $displayRate = rtrim(rtrim($rateNormalized, '0'), '.');
            if ($displayRate === '' || $displayRate === '-') {
                $displayRate = '0';
            }
            $name = 'GST '.$displayRate.'%';
        }

        $effectiveFrom = $attributes['effective_from'] ?? null;
        if ($effectiveFrom === null || $effectiveFrom === '') {
            $effectiveFrom = Carbon::now('Asia/Kolkata')->toDateString();
        }

        // One active open version per rate code — successors close the prior open version.
        $duplicateActiveSameRate = GstRate::query()
            ->where('is_active', true)
            ->whereNull('effective_to')
            ->where('code', '!=', $code)
            ->where('rate_percent', $rateNormalized)
            ->exists();
        if ($duplicateActiveSameRate) {
            throw ValidationException::withMessages([
                'rate_percent' => 'An active GST rate with this percentage already exists under a different code.',
            ]);
        }

        $nextVersion = ((int) GstRate::query()->where('code', $code)->max('version')) + 1;

        return $this->withPlatformTenant(function () use ($code, $name, $rateNormalized, $effectiveFrom, $attributes, $remark, $nextVersion): GstRate {
            return $this->auditedTransaction->run(function () use ($code, $name, $rateNormalized, $effectiveFrom, $attributes, $remark, $nextVersion): GstRate {
                // Close previous active version for this code when a successor starts.
                $previous = GstRate::query()
                    ->where('code', $code)
                    ->where('is_active', true)
                    ->whereNull('effective_to')
                    ->orderByDesc('version')
                    ->first();

                if ($previous !== null) {
                    $previous->effective_to = Carbon::parse($effectiveFrom)->subDay();
                    $previous->is_active = false;
                    $previous->save();
                }

                $rateModel = GstRate::query()->create([
                    'id' => (string) Str::uuid(),
                    'code' => $code,
                    'name' => $name,
                    'rate_percent' => $rateNormalized,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $attributes['effective_to'] ?? null,
                    'version' => $nextVersion,
                    'is_active' => $attributes['is_active'] ?? true,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    GstRate::class,
                    $rateModel->id,
                    remark: $remark,
                    before: null,
                    after: $rateModel->toArray(),
                );

                return $rateModel;
            });
        });
    }

    public function deactivate(User $actor, GstRate $rate, string $remark): GstRate
    {
        $this->assertPlatformMutate($actor);

        return $this->withPlatformTenant(function () use ($rate, $remark): GstRate {
            return $this->auditedTransaction->run(function () use ($rate, $remark): GstRate {
                $before = $rate->toArray();
                $rate->is_active = false;
                $rate->save();

                $this->audit->write(
                    AuditAction::Updated,
                    GstRate::class,
                    $rate->id,
                    remark: $remark,
                    before: $before,
                    after: $rate->fresh()?->toArray(),
                );

                return $rate->fresh() ?? $rate;
            });
        });
    }

    /**
     * Select effective GST rate for a date (for future transactions).
     */
    public function selectForDate(string $code, CarbonInterface|string $onDate): GstRate
    {
        $date = Carbon::parse($onDate)->toDateString();

        $rate = GstRate::query()
            ->where('code', strtoupper($code))
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('version')
            ->first();

        if ($rate === null) {
            throw new TaxDomainException('No active GST rate found for the requested code and date.');
        }

        return $rate;
    }

    /**
     * @return Collection<int, GstRate>
     */
    public function listActive(?string $code = null): Collection
    {
        $builder = GstRate::query()->where('is_active', true)->orderBy('code')->orderByDesc('version');
        if ($code !== null) {
            $builder->where('code', strtoupper($code));
        }

        return $builder->get();
    }

    /**
     * @return array{gst_rate_id: string, code: string, name: string, rate_percent: string, version: int, effective_from: string}
     */
    public function snapshot(GstRate $rate): array
    {
        return $rate->toSnapshot();
    }

    /**
     * Operator-facing creates may omit code; derive a stable internal identifier from the rate.
     */
    public function codeFromRatePercent(string $rateNormalized): string
    {
        $trimmed = rtrim(rtrim($rateNormalized, '0'), '.');
        if ($trimmed === '' || $trimmed === '-') {
            $trimmed = '0';
        }

        return 'GST'.str_replace('.', '_', $trimmed);
    }

    private function normalizeRatePercent(mixed $rate): string
    {
        if (! is_numeric($rate)) {
            throw ValidationException::withMessages(['rate_percent' => 'GST rate (%) must be numeric.']);
        }

        $value = (float) $rate;
        if ($value < 0 || $value > 100) {
            throw ValidationException::withMessages(['rate_percent' => 'GST rate (%) must be between 0 and 100.']);
        }

        return number_format($value, 3, '.', '');
    }

    private function assertPlatformMutate(User $actor): void
    {
        if (! $actor->isInternal()) {
            throw new TaxDomainException('Only internal platform operators may mutate central GST rates.');
        }

        if (! $this->authorizer->allowsInternal($actor, PermissionCodes::PLATFORM_MANAGE_SETTINGS)) {
            throw new TaxDomainException('Not authorized to manage central GST rates.');
        }
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
}
