import { Head } from '@inertiajs/react';

type Props = {
    company: { name: string; gstin: string | null };
    branch: { name: string };
    memo: {
        memo_number: string | null;
        memo_date: string | null;
        pod_number: string | null;
        lr_number: string | null;
        lr_date: string | null;
        customer_name: string | null;
        vehicle_registration: string | null;
        from_station: string | null;
        to_station: string | null;
        hire_number: string | null;
    };
};

export default function BrokerMemoPrint({ company, branch, memo }: Props) {
    return (
        <>
            <Head title={`Memo ${memo.memo_number ?? ''}`} />
            <div className="broker-op-print" data-testid="memo-print">
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
                            Memo / POD {memo.memo_number ?? '—'}
                        </p>
                        <p>Date: {memo.memo_date ?? '—'}</p>
                    </div>
                </header>

                <table>
                    <tbody>
                        <tr>
                            <th>LR Number</th>
                            <td>{memo.lr_number ?? '—'}</td>
                            <th>GR Date</th>
                            <td>{memo.lr_date ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Customer</th>
                            <td>{memo.customer_name ?? '—'}</td>
                            <th>Good Hire</th>
                            <td>{memo.hire_number ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Truck</th>
                            <td>{memo.vehicle_registration ?? '—'}</td>
                            <th>POD Number</th>
                            <td>{memo.pod_number ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Route</th>
                            <td colSpan={3}>
                                {memo.from_station ?? '—'} →{' '}
                                {memo.to_station ?? '—'}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div className="broker-op-print__signatures">
                    <p>Consignee</p>
                    <p>Driver / Transporter</p>
                    <p>Authorised Signatory</p>
                </div>
            </div>
        </>
    );
}
