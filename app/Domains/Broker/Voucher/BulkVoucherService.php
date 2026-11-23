<?php

namespace App\Domains\Broker\Voucher;

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\TransporterVoucher;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bulk transporter voucher creation — same transporter and vehicle only (Rules §11).
 */
final class BulkVoucherService
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly TransporterVoucherService $vouchers = new TransporterVoucherService,
    ) {}

    /**
     * @param  list<array{lorry_hire_id: string, amount: numeric-string|float|int}>  $lines
     * @param  array{
     *     payment_type: VoucherPaymentType|string,
     *     payment_mode: VoucherPaymentMode|string,
     *     remark?: string|null,
     *     bank_cash_account_id?: string|null,
     *     cash_reference?: string|null,
     *     pump_reference?: string|null,
     * }  $common
     * @return array{
     *     transporter_party_id: string,
     *     vehicle_id: string,
     *     lines: list<array{
     *         lorry_hire_id: string,
     *         hire_number: string,
     *         amount: string,
     *         allocatable: string,
     *         accepted: bool,
     *         reason: string|null
     *     }>
     * }
     */
    public function preview(
        Company $company,
        Branch $branch,
        User $actor,
        array $lines,
        array $common,
    ): array {
        $this->assertCanBulk($actor, $company, $branch);
        $hires = $this->resolveUniformHires($company, $branch, $lines);

        $previewLines = [];
        foreach ($lines as $index => $line) {
            $hireId = $line['lorry_hire_id'];
            /** @var LorryHire|null $hire */
            $hire = $hires->get($hireId);
            if ($hire === null) {
                $previewLines[] = [
                    'lorry_hire_id' => $hireId,
                    'hire_number' => '',
                    'amount' => '',
                    'allocatable' => '0.00',
                    'accepted' => false,
                    'reason' => 'Lorry Hire not found.',
                ];

                continue;
            }

            $amount = '';
            $allocatable = '0.00';
            $accepted = false;
            $reason = 'Invalid amount.';

            try {
                $amount = $this->money($line['amount'], 'lines.'.$index.'.amount');
                $allocatable = $this->vouchers->allocatableAmount($hire);
                $accepted = bccomp($amount, $allocatable, 2) !== 1 && bccomp($amount, '0', 2) === 1;
                $reason = $accepted ? null : 'Amount exceeds allocatable balance.';
            } catch (ValidationException) {
            }

            $previewLines[] = [
                'lorry_hire_id' => $hire->id,
                'hire_number' => $hire->hire_number,
                'amount' => $amount,
                'allocatable' => $allocatable,
                'accepted' => $accepted,
                'reason' => $reason,
            ];
        }

        $first = $hires->first();
        if (! $first instanceof LorryHire) {
            throw new BrokerDomainException('Bulk voucher preview requires at least one Lorry Hire.');
        }

        return [
            'transporter_party_id' => $first->transporter_party_id,
            'vehicle_id' => $first->vehicle_id,
            'payment_type' => $this->paymentTypeValue($common['payment_type']),
            'payment_mode' => $this->paymentModeValue($common['payment_mode']),
            'lines' => $previewLines,
        ];
    }

    /**
     * @param  list<array{lorry_hire_id: string, amount: numeric-string|float|int}>  $lines
     * @param  array{
     *     payment_type: VoucherPaymentType|string,
     *     payment_mode: VoucherPaymentMode|string,
     *     remark?: string|null,
     *     bank_cash_account_id?: string|null,
     *     cash_reference?: string|null,
     *     pump_reference?: string|null,
     * }  $common
     * @return list<TransporterVoucher>
     */
    public function createBulk(
        Company $company,
        Branch $branch,
        User $actor,
        array $lines,
        array $common,
    ): array {
        $this->assertCanBulk($actor, $company, $branch);
        $this->assertBrokerEntitled($company);

        $hires = $this->resolveUniformHires($company, $branch, $lines);

        $created = [];
        foreach ($lines as $index => $line) {
            $hireId = $line['lorry_hire_id'];
            $hire = $hires->get($hireId);
            if (! $hire instanceof LorryHire) {
                throw new BrokerDomainException('Bulk voucher line references an unknown Lorry Hire.');
            }

            $amount = $this->money($line['amount'], 'lines.'.$index.'.amount');

            $created[] = $this->vouchers->createForLorryHire($hire, $actor, [
                'amount' => $amount,
                'payment_type' => $this->paymentTypeValue($common['payment_type']),
                'payment_mode' => $this->paymentModeValue($common['payment_mode']),
                'remark' => $common['remark'] ?? null,
                'bank_cash_account_id' => $common['bank_cash_account_id'] ?? null,
                'cash_reference' => $common['cash_reference'] ?? null,
                'pump_reference' => $common['pump_reference'] ?? null,
            ]);
        }

        return $created;
    }

    private function paymentTypeValue(VoucherPaymentType|string $value): string
    {
        return $value instanceof VoucherPaymentType ? $value->value : $value;
    }

    private function paymentModeValue(VoucherPaymentMode|string $value): string
    {
        return $value instanceof VoucherPaymentMode ? $value->value : $value;
    }

    /**
     * @param  list<array{lorry_hire_id: string, amount?: mixed}>  $lines
     * @return Collection<string, LorryHire>
     */
    private function resolveUniformHires(Company $company, Branch $branch, array $lines): Collection
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'At least one Lorry Hire line is required.',
            ]);
        }

        $hireIds = [];
        foreach ($lines as $index => $line) {
            $hireId = $line['lorry_hire_id'];
            if ($hireId === '') {
                throw ValidationException::withMessages([
                    "lines.{$index}.lorry_hire_id" => 'Lorry Hire is required.',
                ]);
            }
            $hireIds[] = $hireId;
        }

        $hires = LorryHire::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $hireIds)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        if ($hires->count() !== count(array_unique($hireIds))) {
            throw new BrokerDomainException('One or more Lorry Hires were not found.');
        }

        $transporterId = null;
        $vehicleId = null;
        foreach ($hires as $hire) {
            if ($transporterId === null) {
                $transporterId = $hire->transporter_party_id;
                $vehicleId = $hire->vehicle_id;
            } elseif ($transporterId !== $hire->transporter_party_id || $vehicleId !== $hire->vehicle_id) {
                throw new BrokerConflictException('Bulk vouchers require the same transporter and vehicle.');
            }
        }

        return $hires;
    }

    private function assertCanBulk(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_BULK, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized for bulk voucher operations.');
        }
    }

    private function assertBrokerEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerDomainException('Broker module is not entitled for this company.');
        }
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, string $field): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid amount is required.']);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
