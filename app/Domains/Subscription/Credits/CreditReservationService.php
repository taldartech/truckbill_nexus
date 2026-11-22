<?php

namespace App\Domains\Subscription\Credits;

use App\Domains\Subscription\Contract\CompanySubscriptionContractService;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\SubscriptionCreditLedger;
use App\Models\SubscriptionCreditReservation;
use App\Support\Audit\AuditedTransaction;
use Illuminate\Support\Str;

/**
 * Idempotent credit reservation / commit / release (no double consumption).
 * Reservations hold availability without mutating the ledger until commit.
 */
final class CreditReservationService
{
    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly CompanySubscriptionContractService $contracts = new CompanySubscriptionContractService,
        private readonly SubscriptionEntitlementGate $entitlements = new SubscriptionEntitlementGate,
    ) {}

    public function reserve(
        SubscriptionCreditLedger $ledger,
        string $amount,
        string $idempotencyKey,
        string $reason,
    ): SubscriptionCreditReservation {
        if (DecimalMath::compare($amount, '0', 4) <= 0) {
            throw new SubscriptionDomainException('Reservation amount must be positive.');
        }

        $company = Company::query()->whereKey($ledger->company_id)->firstOrFail();
        if (! $this->entitlements->allowsCreditConsumption($company)) {
            throw new SubscriptionDomainException(
                $this->entitlements->evaluate($company)['restriction_reason']
                    ?? 'Credit consumption is not permitted for the current subscription lifecycle status.'
            );
        }

        return $this->tx->run(function () use ($ledger, $amount, $idempotencyKey, $reason): SubscriptionCreditReservation {
            $existing = SubscriptionCreditReservation::query()
                ->where('ledger_id', $ledger->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var SubscriptionCreditLedger $locked */
            $locked = SubscriptionCreditLedger::query()->whereKey($ledger->id)->lockForUpdate()->firstOrFail();

            $held = SubscriptionCreditReservation::query()
                ->where('ledger_id', $locked->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get()
                ->reduce(fn (string $carry, SubscriptionCreditReservation $row): string => DecimalMath::add($carry, (string) $row->amount, 4), '0.0000');

            $available = DecimalMath::sub((string) $locked->balance, $held, 4);
            if (DecimalMath::compare($available, $amount, 4) < 0) {
                throw new SubscriptionDomainException('Insufficient credit balance for reservation.');
            }

            return SubscriptionCreditReservation::query()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $locked->company_id,
                'ledger_id' => $locked->id,
                'amount' => $amount,
                'status' => 'reserved',
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'reserved_at' => now(),
            ]);
        });
    }

    public function commit(SubscriptionCreditReservation $reservation): SubscriptionCreditReservation
    {
        return $this->tx->run(function () use ($reservation): SubscriptionCreditReservation {
            /** @var SubscriptionCreditReservation $locked */
            $locked = SubscriptionCreditReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'committed') {
                return $locked;
            }
            if ($locked->status !== 'reserved') {
                throw new SubscriptionDomainException('Only reserved credits can be committed.');
            }

            $ledger = SubscriptionCreditLedger::query()->whereKey($locked->ledger_id)->lockForUpdate()->firstOrFail();
            $this->contracts->appendCreditEntry(
                $ledger,
                (string) $locked->amount,
                'consume',
                $locked->reason,
                'commit:'.$locked->idempotency_key,
            );

            $locked->status = 'committed';
            $locked->finalized_at = now();
            $locked->save();

            return $locked->fresh() ?? $locked;
        });
    }

    public function release(SubscriptionCreditReservation $reservation): SubscriptionCreditReservation
    {
        return $this->tx->run(function () use ($reservation): SubscriptionCreditReservation {
            /** @var SubscriptionCreditReservation $locked */
            $locked = SubscriptionCreditReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'released') {
                return $locked;
            }
            if ($locked->status !== 'reserved') {
                throw new SubscriptionDomainException('Only reserved credits can be released.');
            }

            $locked->status = 'released';
            $locked->finalized_at = now();
            $locked->save();

            return $locked;
        });
    }
}
