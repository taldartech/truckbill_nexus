<?php

namespace App\Domains\Broker\Voucher;

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartyRole;
use App\Models\TransporterVoucher;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bank payout CSV for Pending / In Progress bank-mode transporter vouchers.
 * Legacy reference: VoucherPaymentController::exportPayoutFile (read-only treasury file).
 */
final class VoucherPayoutExportService
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
    ) {}

    /**
     * @param  array{
     *     status?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     * }  $filters
     */
    public function streamCsv(
        Company $company,
        Branch $branch,
        User $actor,
        array $filters = [],
    ): StreamedResponse {
        $this->assertCanExport($actor, $company, $branch);

        $vouchers = $this->queryVouchers($company, $branch, $filters);
        $filename = 'bank_payout_'.$company->id.'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($vouchers): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Voucher No',
                'Payment Date',
                'Debit Account No',
                'Debit Bank',
                'Beneficiary Name',
                'Beneficiary Account / ID',
                'Amount',
                'Payment Mode',
                'LR No',
                'Truck No',
                'Transporter PAN',
                'Reference / UTR',
                'Status',
                'Narration / Remarks',
            ]);

            foreach ($vouchers as $voucher) {
                fputcsv($handle, $this->row($voucher));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{
     *     status?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     * }  $filters
     * @return Collection<int, TransporterVoucher>
     */
    private function queryVouchers(Company $company, Branch $branch, array $filters): Collection
    {
        $builder = TransporterVoucher::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->where('payment_mode', VoucherPaymentMode::Bank->value)
            ->with([
                'bankCashAccount',
                'transporter',
                'lorryHire.lorryReceipt',
                'lorryHire.vehicle',
            ])
            ->orderByDesc('voucher_date')
            ->orderByDesc('created_at');

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $builder->where('status', $status);
        } else {
            $builder->whereIn('status', [
                VoucherStatus::Pending->value,
                VoucherStatus::InProgress->value,
            ]);
        }

        if (filled($filters['date_from'] ?? null)) {
            $builder->whereDate('voucher_date', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $builder->whereDate('voucher_date', '<=', $filters['date_to']);
        }

        return $builder->limit(5000)->get();
    }

    /**
     * @return list<string>
     */
    private function row(TransporterVoucher $voucher): array
    {
        $account = $voucher->bankCashAccount;
        $transporter = $voucher->transporter;
        $hire = $voucher->lorryHire;
        $lr = $hire?->lorryReceipt;
        $lrNo = $lr?->lr_number ?? '-';
        $truckNo = $hire?->vehicle?->registration_number ?? '-';
        $beneficiaryId = $this->beneficiaryId($voucher);
        $debitAcct = $account !== null
            ? (string) ($account->getAttributes()['account_number'] ?? '')
            : '';
        $debitBank = $account?->bank_name ?? $account?->name ?? '';
        $amount = number_format((float) $voucher->amount, 2, '.', '');
        $mode = $voucher->payment_mode?->value ?? 'bank';
        if (bccomp($amount, '200000', 2) !== -1 && $mode === VoucherPaymentMode::Bank->value) {
            $mode = 'RTGS';
        } elseif ($mode === VoucherPaymentMode::Bank->value) {
            $mode = 'NEFT';
        }

        $narration = trim((string) ($voucher->remark ?? ''));
        if ($narration === '') {
            $narration = "Freight payout LR {$lrNo} Truck {$truckNo}";
        }

        return [
            $voucher->voucher_number,
            $voucher->voucher_date?->format('Y-m-d') ?? '',
            $debitAcct,
            $debitBank,
            $transporter?->name ?? 'Vendor',
            $beneficiaryId,
            $amount,
            strtoupper($mode),
            $lrNo,
            $truckNo,
            (string) ($transporter?->pan ?? ''),
            (string) ($voucher->cash_reference ?? ''),
            $voucher->status->value,
            $narration,
        ];
    }

    private function beneficiaryId(TransporterVoucher $voucher): string
    {
        if ($voucher->transporter_party_id === null) {
            return '';
        }

        $role = PartyRole::query()
            ->where('company_id', $voucher->company_id)
            ->where('party_id', $voucher->transporter_party_id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->first();

        $attrs = is_array($role?->role_attributes) ? $role->role_attributes : [];

        foreach (['beneficiary_id', 'bank_account_number', 'account_number'] as $key) {
            $value = trim((string) ($attrs[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function assertCanExport(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Broker)) {
            throw new BrokerAuthorizationException('Broker module is not entitled for this company.');
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_VIEW, $company, $branch)) {
            throw new BrokerAuthorizationException('Not authorized to export voucher payouts.');
        }
    }
}
