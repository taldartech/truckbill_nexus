import { Head } from '@inertiajs/react';

type Props = {
    company: { name: string; gstin: string | null };
    branch: { name: string };
    voucher: {
        voucher_number: string;
        voucher_date: string | null;
        status: string;
        payment_type: string;
        payment_mode: string | null;
        amount: string | null;
        payee_name: string | null;
        remark: string | null;
        bank_cash_account_name?: string | null;
        lorry_hire?: {
            hire_number: string | null;
            lr_number: string | null;
            vehicle_registration: string | null;
        } | null;
    };
    sampleBanner: string;
};

export default function VoucherPaymentPrint({
    company,
    branch,
    voucher,
    sampleBanner,
}: Props) {
    return (
        <>
            <Head title={`Print ${voucher.voucher_number}`} />
            <div
                className="broker-op-print"
                data-testid="voucher-payment-print"
            >
                <p className="broker-op-print__banner">{sampleBanner}</p>
                <header className="broker-op-print__header">
                    <div>
                        <h1 className="broker-op-print__title">
                            {company.name}
                        </h1>
                        <p>Branch: {branch.name}</p>
                        <p>GSTIN: {company.gstin ?? '—'}</p>
                    </div>
                    <div className="text-end">
                        <p className="broker-op-print__title">
                            Voucher {voucher.voucher_number}
                        </p>
                        <p>Date: {voucher.voucher_date ?? '—'}</p>
                        <p>Status: {voucher.status}</p>
                    </div>
                </header>

                <table>
                    <tbody>
                        <tr>
                            <th>Good Hire</th>
                            <td>{voucher.lorry_hire?.hire_number ?? '—'}</td>
                            <th>LR No</th>
                            <td>{voucher.lorry_hire?.lr_number ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Payee</th>
                            <td>{voucher.payee_name ?? '—'}</td>
                            <th>Truck</th>
                            <td>
                                {voucher.lorry_hire?.vehicle_registration ??
                                    '—'}
                            </td>
                        </tr>
                        <tr>
                            <th>Payment Type</th>
                            <td>{voucher.payment_type}</td>
                            <th>Payment Mode</th>
                            <td>{voucher.payment_mode ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Bank / Cash</th>
                            <td>{voucher.bank_cash_account_name ?? '—'}</td>
                            <th className="text-end">Amount Paid</th>
                            <td className="text-end">
                                {voucher.amount ?? '—'}
                            </td>
                        </tr>
                    </tbody>
                </table>

                {voucher.remark ? (
                    <p>
                        <strong>Remark:</strong> {voucher.remark}
                    </p>
                ) : null}

                <div className="broker-op-print__signatures">
                    <p>Receiver</p>
                    <p>Prepared By</p>
                    <p>Authorised Signatory</p>
                </div>
            </div>
        </>
    );
}
