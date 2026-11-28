import { Head } from '@inertiajs/react';

type Props = {
    company: { name: string; gstin: string | null };
    branch: { name: string };
    lorryHire: {
        hire_number: string;
        hire_date: string | null;
        lr_number: string | null;
        lr_date: string | null;
        billing_customer_name: string | null;
        transporter_name: string | null;
        vehicle_registration: string | null;
        loading_station: string | null;
        unloading_station: string | null;
        freight_total: string | null;
        tds_amount: string | null;
        net_payable: string | null;
        outstanding_amount: string | null;
        remarks: string | null;
        goods_lines: Array<{
            goods_label?: string | null;
            rate_type_label?: string | null;
            rate: string | null;
            freight_amount: string | null;
            loading_weight: string | null;
        }>;
        custom_amounts: Array<{ description: string; amount: string | null }>;
    };
    sampleBanner: string;
};

export default function LorryHirePrint({
    company,
    branch,
    lorryHire,
    sampleBanner,
}: Props) {
    return (
        <>
            <Head title={`Print ${lorryHire.hire_number}`} />
            <div className="broker-op-print" data-testid="lorry-hire-print">
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
                            Good Hire {lorryHire.hire_number}
                        </p>
                        <p>
                            GR / Hire Date:{' '}
                            {lorryHire.lr_date ?? lorryHire.hire_date ?? '—'}
                        </p>
                    </div>
                </header>

                <table>
                    <tbody>
                        <tr>
                            <th>LR Number</th>
                            <td>{lorryHire.lr_number ?? '—'}</td>
                            <th>Customer</th>
                            <td>{lorryHire.billing_customer_name ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Payee</th>
                            <td>{lorryHire.transporter_name ?? '—'}</td>
                            <th>Truck</th>
                            <td>{lorryHire.vehicle_registration ?? '—'}</td>
                        </tr>
                        <tr>
                            <th>Route</th>
                            <td colSpan={3}>
                                {lorryHire.loading_station ?? '—'} →{' '}
                                {lorryHire.unloading_station ?? '—'}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table>
                    <thead>
                        <tr>
                            <th>Goods</th>
                            <th>Rate Type</th>
                            <th className="text-end">Rate</th>
                            <th className="text-end">Freight</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lorryHire.goods_lines.map((line, index) => (
                            <tr key={`${line.goods_label ?? 'line'}-${index}`}>
                                <td>{line.goods_label ?? '—'}</td>
                                <td>{line.rate_type_label ?? '—'}</td>
                                <td className="text-end">{line.rate ?? '—'}</td>
                                <td className="text-end">
                                    {line.freight_amount ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {lorryHire.custom_amounts.length > 0 ? (
                    <table>
                        <thead>
                            <tr>
                                <th>Custom Amount</th>
                                <th className="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lorryHire.custom_amounts.map((row) => (
                                <tr key={row.description}>
                                    <td>{row.description}</td>
                                    <td className="text-end">
                                        {row.amount ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : null}

                <table>
                    <tbody>
                        <tr>
                            <th>Transporter Freight</th>
                            <td className="text-end">
                                {lorryHire.freight_total ?? '—'}
                            </td>
                        </tr>
                        <tr>
                            <th>TDS</th>
                            <td className="text-end">
                                {lorryHire.tds_amount ?? '—'}
                            </td>
                        </tr>
                        <tr>
                            <th>Net Payable</th>
                            <td className="text-end">
                                {lorryHire.net_payable ?? '—'}
                            </td>
                        </tr>
                        <tr>
                            <th>Outstanding Balance</th>
                            <td className="text-end">
                                {lorryHire.outstanding_amount ?? '—'}
                            </td>
                        </tr>
                    </tbody>
                </table>

                {lorryHire.remarks ? (
                    <p>
                        <strong>Remarks:</strong> {lorryHire.remarks}
                    </p>
                ) : null}

                <div className="broker-op-print__signatures">
                    <p>Prepared By</p>
                    <p>Checked By</p>
                    <p>Authorised Signatory</p>
                </div>
            </div>
        </>
    );
}
