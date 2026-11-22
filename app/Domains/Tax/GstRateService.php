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

        $code = strtoupper(trim((string) ($attributes['code'] ?? '')));
        $name = trim((string) ($attributes['name'] ?? ''));
        $rate = $attributes['rate_percent'] ?? null;
        $effectiveFrom = $attributes['effective_from'] ?? null;

        if ($code === '' || $name === '' || $rate === null || $effectiveFrom === null) {
            throw ValidationException::withMessages(['code' => 'code, name, rate_percent and effective_from are required.']);
        }

        $nextVersion = ((int) GstRate::query()->where('code', $code)->max('version')) + 1;

        return $this->withPlatformTenant(function () use ($code, $name, $rate, $effectiveFrom, $attributes, $remark, $nextVersion): GstRate {
            return $this->auditedTransaction->run(function () use ($code, $name, $rate, $effectiveFrom, $attributes, $remark, $nextVersion): GstRate {
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
                    'rate_percent' => $rate,
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
