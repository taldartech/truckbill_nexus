<?php

namespace App\Domains\Broker\Voucher;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\TransporterVoucher;
use App\Models\User;
use App\Support\Audit\AuditedTransaction;
use App\Support\Presentation\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Records Voucher Payments for one or more Lorry Hires of the same transporter and vehicle (Rules §11).
 *
 * The whole request is one transaction: any rejected line rolls back every voucher. Adjust in Advance
 * locks the transporter so concurrent requests cannot consume the same Book Balance.
 */
final class VoucherPaymentService
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly TransporterVoucherService $vouchers = new TransporterVoucherService,
        private readonly TransporterBookBalanceService $bookBalance = new TransporterBookBalanceService,
    ) {}

    /**
     * @param  list<array{lorry_hire_id: string, amount: numeric-string|float|int}>  $lines
     * @param  array{
     *     payment_type: string,
     *     payment_mode: string,
     *     voucher_date?: string|null,
     *     remark?: string|null,
     *     bank_cash_account_id?: string|null,
     *     cash_reference?: string|null,
     *     pump_reference?: string|null,
     *     confirm_duplicate?: bool,
     * }  $common
     * @return list<TransporterVoucher>
     */
    public function record(Company $company, Branch $branch, User $actor, array $lines, array $common): array
    {
        if (count($lines) > 1 && ! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_BULK, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized for bulk voucher operations.');
        }

        $hires = $this->resolvePayableHires($company, $branch, $lines);
        $amounts = array_map(fn (array $line): string => $this->money($line['amount']), $lines);

        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $lines, $common, $hires, $amounts): array {
            if (VoucherPaymentMode::tryFrom($common['payment_mode']) === VoucherPaymentMode::AdjustInAdvance) {
                $this->assertTotalWithinBookBalance($company, $branch, $hires->first(), $amounts);
            }

            $created = [];
            foreach ($lines as $index => $line) {
                $created[] = $this->recordLine($hires->get($line['lorry_hire_id']), $actor, $index, [
                    ...$common,
                    'amount' => $amounts[$index],
                ]);
            }

            return $created;
        });
    }

    /**
     * Hires a payment modal may open: same transporter and vehicle, active, with remaining payable.
     *
     * @param  list<string>  $hireIds
     * @return Collection<string, LorryHire>
     */
    public function payableHires(Company $company, Branch $branch, array $hireIds): Collection
    {
        return $this->resolvePayableHires(
            $company,
            $branch,
            array_map(fn (string $id): array => ['lorry_hire_id' => $id], $hireIds),
        );
    }

    /**
     * @param  list<numeric-string>  $amounts
     */
    private function assertTotalWithinBookBalance(Company $company, Branch $branch, LorryHire $hire, array $amounts): void
    {
        $transporterId = (string) $hire->transporter_party_id;
        $this->bookBalance->lockTransporter($company, $transporterId);

        if (count($amounts) === 1) {
            $this->bookBalance->assertAdjustmentWithinBalance($company, $branch, $transporterId, $amounts[0], 'lines.0.amount');

            return;
        }

        $total = array_reduce($amounts, fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');
        $available = $this->bookBalance->availableBalance($company, $branch, $transporterId);
        if (bccomp($total, $available, 2) === 1) {
            throw ValidationException::withMessages([
                'lines' => TransporterBookBalanceService::exceedsBalanceMessage('Total adjustment of '.Money::inr($total), $available),
            ]);
        }
    }

    /**
     * @param  array{
     *     amount: string,
     *     payment_type: string,
     *     payment_mode: string,
     *     voucher_date?: string|null,
     *     remark?: string|null,
     *     bank_cash_account_id?: string|null,
     *     cash_reference?: string|null,
     *     pump_reference?: string|null,
     *     confirm_duplicate?: bool,
     * }  $payload
     */
    private function recordLine(LorryHire $hire, User $actor, int $index, array $payload): TransporterVoucher
    {
        try {
            return $this->vouchers->createForLorryHire($hire, $actor, $payload);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $errors[$key === 'amount' ? "lines.{$index}.amount" : $key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        } catch (BrokerConflictException $e) {
            throw ValidationException::withMessages(["lines.{$index}.amount" => $e->getMessage()]);
        }
    }

    /**
     * @param  list<array{lorry_hire_id: string, amount?: mixed}>  $lines
     * @return Collection<string, LorryHire>
     */
    private function resolvePayableHires(Company $company, Branch $branch, array $lines): Collection
    {
        $hireIds = array_column($lines, 'lorry_hire_id');
        if ($hireIds === []) {
            throw ValidationException::withMessages(['lines' => 'Select at least one Lorry Hire.']);
        }
        if (count($hireIds) !== count(array_unique($hireIds))) {
            throw ValidationException::withMessages(['lines' => 'Each Lorry Hire can be paid only once per voucher payment.']);
        }

        $hires = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $hireIds)
            ->whereNull('deleted_at')
            ->with(['transporter', 'vehicle', 'lorryReceipt', 'adjustments', 'vouchers', 'bookBalanceEntries'])
            ->get()
            ->keyBy('id');

        if ($hires->count() !== count($hireIds)) {
            throw new BrokerDomainException('One or more Lorry Hires were not found.');
        }

        $first = $hires->first();
        foreach ($hires as $hire) {
            if ($hire->status === LorryHireStatus::Cancelled) {
                throw new BrokerConflictException('Lorry Hire '.$hire->hire_number.' is cancelled.');
            }
            if ($hire->transporter_party_id !== $first->transporter_party_id || $hire->vehicle_id !== $first->vehicle_id) {
                throw new BrokerConflictException('Selected Lorry Hires must have the same Payee and Truck.');
            }
            if (bccomp($this->vouchers->allocatableAmount($hire), '0', 2) !== 1) {
                throw new BrokerConflictException('Lorry Hire '.$hire->hire_number.' has no remaining payable.');
            }
        }

        return $hires;
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
