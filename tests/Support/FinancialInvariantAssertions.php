<?php

use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Models\BrokerInvoice;
use App\Models\LorryHire;
use App\Models\TripInvoice;
use PHPUnit\Framework\Assert;

if (! function_exists('financialOwnFleetInvoiceReady')) {
    /**
     * @param  array<string, mixed>  $ctx
     */
    function financialOwnFleetInvoiceReady(array $ctx): TripInvoice
    {
        tripEnsureCompanyGstSnapshot($ctx['company']);
        $customer = tripCreateBillingCustomer($ctx, 'AAAHP9999A');
        $trip = tripCreatePendingTrip($ctx);
        $lr = $trip->lorryReceipt()->firstOrFail();
        tripAssignBillingCustomer($lr, $customer, $ctx);
        tripCompleteTrip($ctx, $trip->fresh());

        return app(TripInvoiceService::class)->createIndividual(
            $ctx['company'],
            $ctx['branch'],
            $ctx['member'],
            $lr->fresh()->id,
            ['number_mode' => 'manual', 'manual_number' => 'FINV-'.uniqid()],
        );
    }
}

if (! function_exists('assertBcEqual')) {
    function assertBcEqual(string $expected, string $actual, string $label = 'amount'): void
    {
        Assert::assertSame(
            bcmul($expected, '1', 2),
            bcmul($actual, '1', 2),
            $label.' expected '.$expected.' but got '.$actual,
        );
    }
}

if (! function_exists('brokerInvoiceSettledTotal')) {
    function brokerInvoiceSettledTotal(BrokerInvoice $invoice): string
    {
        $invoice->loadMissing('payments');
        $total = '0.00';

        foreach ($invoice->payments as $payment) {
            $settlement = bcadd(
                (string) $payment->amount_received,
                bcadd((string) $payment->tds_amount, (string) $payment->deduction_amount, 2),
                2,
            );
            $total = bcadd($total, $settlement, 2);
        }

        return $total;
    }
}

if (! function_exists('assertOwnFleetInvoiceSettlementInvariant')) {
    function assertOwnFleetInvoiceSettlementInvariant(TripInvoice $invoice): void
    {
        $invoice->refresh();
        $reconciled = bcadd((string) $invoice->settlement_total, (string) $invoice->balance_due, 2);
        assertBcEqual((string) $invoice->invoice_total, $reconciled, 'OwnFleet invoice_total = settlement + balance');
    }
}

if (! function_exists('assertBrokerInvoiceSettlementInvariant')) {
    function assertBrokerInvoiceSettlementInvariant(BrokerInvoice $invoice): void
    {
        $invoice->refresh();
        $settled = brokerInvoiceSettledTotal($invoice);
        $reconciled = bcadd($settled, (string) $invoice->balance_due, 2);
        assertBcEqual((string) $invoice->invoice_total, $reconciled, 'Broker invoice_total = payments settled + balance');
    }
}

if (! function_exists('assertLorryHirePayableInvariant')) {
    function assertLorryHirePayableInvariant(LorryHire $hire): void
    {
        $hire->refresh();
        $summary = app(LorryHireCalculationService::class)->summarize($hire);

        assertBcEqual($summary['outstanding_amount'], (string) $hire->outstanding_amount, 'Lorry hire outstanding matches calculation');

        $paidFromVouchers = '0.00';
        $hire->loadMissing('vouchers');
        foreach ($hire->vouchers as $voucher) {
            if ($voucher->status->value === 'paid') {
                $paidFromVouchers = bcadd($paidFromVouchers, (string) $voucher->amount, 2);
            }
        }

        assertBcEqual($paidFromVouchers, (string) $hire->paid_amount, 'Lorry hire paid_amount equals paid voucher total');

        $bookDelta = '0.00';
        $hire->loadMissing('bookBalanceEntries');
        foreach ($hire->bookBalanceEntries as $entry) {
            $bookDelta = bcadd($bookDelta, (string) $entry->amount, 2);
        }

        $reconciled = bcadd((string) $hire->paid_amount, (string) $hire->outstanding_amount, 2);
        $netFromParts = bcsub($reconciled, $bookDelta, 2);
        assertBcEqual($summary['net_payable'], $netFromParts, 'Lorry hire net_payable = paid + outstanding − book delta');
    }
}
