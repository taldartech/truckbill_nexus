<?php

namespace App\Domains\OwnFleet\Driver;

use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DriverAdvanceEntry;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Trip;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Presentation\FreightAmountPresentation;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet driver advance ledger: Trip/driver advances, expense adjustments, and cash recoveries.
 */
final class DriverAdvanceService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly OperationalAccountingPoster $accounting = new OperationalAccountingPoster,
        private readonly ?CompanyCapabilityResolver $capabilities = null,
        private readonly FreightAmountPresentation $freightPresentation = new FreightAmountPresentation,
    ) {}

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     driver_balances: list<array{
     *         driver_party_id: string,
     *         driver_name: string,
     *         advances: string|null,
     *         settlements: string|null,
     *         recoveries: string|null,
     *         net_balance: string|null
     *     }>,
     *     total_outstanding: string|null
     * }
     */
    public function summaryForBranch(
        Company $company,
        Branch $branch,
        User $actor,
        ?string $driverPartyId = null,
        ?string $tripId = null,
        bool $maySeeFreight = true,
    ): array {
        $this->assertEntitled($company, mutate: false);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_VIEW);

        $query = DriverAdvanceEntry::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['driver', 'trip.lorryReceipt'])
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at');

        if (filled($driverPartyId)) {
            $query->where('driver_party_id', $driverPartyId);
        }

        if (filled($tripId)) {
            $query->where('trip_id', $tripId);
        }

        $entries = $query->limit(250)->get();

        $allDriverRows = DriverAdvanceEntry::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->with('driver')
            ->get();

        $grouped = [];
        foreach ($allDriverRows as $row) {
            $id = (string) $row->driver_party_id;
            if (! isset($grouped[$id])) {
                $grouped[$id] = [
                    'driver_party_id' => $id,
                    'driver_name' => $row->driver->name,
                    'advances' => '0.00',
                    'settlements' => '0.00',
                    'recoveries' => '0.00',
                    'net_balance' => '0.00',
                ];
            }

            $amount = number_format((float) $row->amount, 2, '.', '');
            if ($row->entry_type === 'advance') {
                $grouped[$id]['advances'] = bcadd($grouped[$id]['advances'], $amount, 2);
                $grouped[$id]['net_balance'] = bcadd($grouped[$id]['net_balance'], $amount, 2);
            } elseif ($row->entry_type === 'settlement') {
                $grouped[$id]['settlements'] = bcadd($grouped[$id]['settlements'], $amount, 2);
                $grouped[$id]['net_balance'] = bcsub($grouped[$id]['net_balance'], $amount, 2);
            } else {
                $grouped[$id]['recoveries'] = bcadd($grouped[$id]['recoveries'], $amount, 2);
                $grouped[$id]['net_balance'] = bcsub($grouped[$id]['net_balance'], $amount, 2);
            }
        }

        $totalOutstanding = '0.00';
        foreach ($grouped as &$item) {
            $totalOutstanding = bcadd($totalOutstanding, $item['net_balance'], 2);
            if (! $maySeeFreight) {
                $item['advances'] = null;
                $item['settlements'] = null;
                $item['recoveries'] = null;
                $item['net_balance'] = null;
            }
        }
        unset($item);

        return [
            'entries' => array_values($entries->map(fn (DriverAdvanceEntry $entry): array => [
                'id' => $entry->id,
                'driver_party_id' => $entry->driver_party_id,
                'driver_name' => $entry->driver->name,
                'trip_id' => $entry->trip_id,
                'trip_number' => $entry->trip?->lorryReceipt?->lr_number,
                'entry_date' => $entry->entry_date->toDateString(),
                'entry_type' => $entry->entry_type,
                'amount' => $this->freightPresentation->presentAmount($entry->amount, $maySeeFreight),
                'payment_mode' => $entry->payment_mode,
                'bank_cash_account_id' => $entry->bank_cash_account_id,
                'reference' => $entry->reference,
                'remark' => $entry->remark,
            ])->all()),
            'driver_balances' => array_values($grouped),
            'total_outstanding' => $this->freightPresentation->presentAmount($totalOutstanding, $maySeeFreight),
        ];
    }

    /**
     * @param  array{
     *     driver_party_id: string,
     *     trip_id?: string|null,
     *     entry_date?: string|null,
     *     entry_type: string,
     *     amount: numeric-string|float|int,
     *     payment_mode?: string|null,
     *     bank_cash_account_id?: string|null,
     *     reference?: string|null,
     *     remark?: string|null,
     * }  $payload
     */
    public function record(Company $company, Branch $branch, User $actor, array $payload): DriverAdvanceEntry
    {
        $this->assertEntitled($company, mutate: true);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::TRIP_EDIT);

        if ($branch->company_id !== $company->id) {
            throw new TripDomainException('Branch does not belong to company.');
        }

        $entryType = (string) $payload['entry_type'];
        if (! in_array($entryType, ['advance', 'settlement', 'recovery'], true)) {
            throw ValidationException::withMessages(['entry_type' => 'Entry type must be advance, settlement, or recovery.']);
        }

        $driverId = (string) $payload['driver_party_id'];
        $driver = Party::query()
            ->whereKey($driverId)
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->first();

        if ($driver === null || ! PartyRole::query()->where('party_id', $driver->id)->where('role', PartyRoleType::Driver->value)->exists()) {
            throw ValidationException::withMessages(['driver_party_id' => 'Selected party must be an active Driver.']);
        }

        $tripId = filled($payload['trip_id'] ?? null) ? (string) $payload['trip_id'] : null;
        if ($tripId !== null) {
            $tripExists = Trip::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereKey($tripId)
                ->whereNull('deleted_at')
                ->exists();
            if (! $tripExists) {
                throw ValidationException::withMessages(['trip_id' => 'Trip was not found in this branch.']);
            }
        }

        $amount = number_format((float) $payload['amount'], 2, '.', '');
        if (bccomp($amount, '0.00', 2) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $paymentMode = (string) ($payload['payment_mode'] ?? ($entryType === 'settlement' ? 'adjustment' : 'cash'));
        if (! in_array($paymentMode, ['cash', 'bank', 'adjustment'], true)) {
            throw ValidationException::withMessages(['payment_mode' => 'Invalid payment mode.']);
        }

        $bankCashId = filled($payload['bank_cash_account_id'] ?? null)
            ? (string) $payload['bank_cash_account_id']
            : null;
        if (in_array($entryType, ['advance', 'recovery'], true) && $bankCashId === null) {
            throw ValidationException::withMessages([
                'bank_cash_account_id' => 'Cash or Bank account is required for cash/bank driver advances and recoveries.',
            ]);
        }

        if ($bankCashId !== null) {
            $account = BankCashAccount::query()
                ->where('company_id', $company->id)
                ->whereKey($bankCashId)
                ->whereNull('deleted_at')
                ->first();
            if ($account === null || ($account->branch_id !== null && $account->branch_id !== $branch->id)) {
                throw ValidationException::withMessages(['bank_cash_account_id' => 'Bank/Cash account is not available in this branch.']);
            }
        }

        $entryDate = filled($payload['entry_date'] ?? null)
            ? CarbonImmutable::parse((string) $payload['entry_date'])->toDateString()
            : now()->toDateString();

        return $this->auditedTransaction->run(function () use (
            $company,
            $branch,
            $actor,
            $driverId,
            $tripId,
            $entryDate,
            $entryType,
            $amount,
            $paymentMode,
            $bankCashId,
            $payload,
        ): DriverAdvanceEntry {
            return $this->withTenant($company, $branch, function () use (
                $company,
                $branch,
                $actor,
                $driverId,
                $tripId,
                $entryDate,
                $entryType,
                $amount,
                $paymentMode,
                $bankCashId,
                $payload,
            ): DriverAdvanceEntry {
                $entry = DriverAdvanceEntry::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'driver_party_id' => $driverId,
                    'trip_id' => $tripId,
                    'entry_date' => $entryDate,
                    'entry_type' => $entryType,
                    'amount' => $amount,
                    'payment_mode' => $paymentMode,
                    'bank_cash_account_id' => $bankCashId,
                    'reference' => filled($payload['reference'] ?? null) ? trim((string) $payload['reference']) : null,
                    'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                    'created_by_user_id' => $actor->id,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    DriverAdvanceEntry::class,
                    $entry->id,
                    remark: 'Driver '.$entryType.' recorded',
                    before: null,
                    after: $entry->toArray(),
                );

                $fresh = $entry->fresh(['driver', 'trip']) ?? $entry;
                $this->accounting->postDriverAdvanceEntry($fresh, $actor);

                return $fresh;
            });
        });
    }

    public function driverBalance(Company $company, string $driverPartyId): string
    {
        $rows = DriverAdvanceEntry::query()
            ->where('company_id', $company->id)
            ->where('driver_party_id', $driverPartyId)
            ->whereNull('deleted_at')
            ->get(['entry_type', 'amount']);

        $balance = '0.00';
        foreach ($rows as $row) {
            $amt = number_format((float) $row->amount, 2, '.', '');
            $balance = $row->entry_type === 'advance'
                ? bcadd($balance, $amt, 2)
                : bcsub($balance, $amt, 2);
        }

        return $balance;
    }

    private function assertEntitled(Company $company, bool $mutate): void
    {
        $resolver = $this->capabilities ?? app(CompanyCapabilityResolver::class);
        $allowed = $mutate
            ? $resolver->allowsMutate($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS)
            : $resolver->allowsRead($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS);

        if (! $allowed) {
            throw new TripAuthorizationException('OwnFleet driver module is not entitled.');
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new TripAuthorizationException('Not authorized for driver advance operations.');
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
