import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type LorryReceipt = {
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    status: string;
    from_text: string | null;
    to_text: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    consignor_name: string | null;
    consignee_name: string | null;
    goods_value: string | null;
    freight_total: string;
    additional_charges_total: string;
    goods: Array<{
        id: string;
        goods_description: string | null;
        package_count: string | null;
        actual_weight: string | null;
        charged_weight: string | null;
        freight_rate_type: string;
        freight_rate: string;
        freight_amount: string;
    }>;
    eway_bills: Array<{
        id: string;
        ewb_number: string;
        valid_until: string | null;
    }>;
    additional_charges: Array<{
        id: string;
        description: string;
        amount: string;
    }>;
};

type Props = {
    company: { name: string };
    branch: { name: string };
    lorryReceipt: LorryReceipt;
    urls: { show: string };
};

export default function LorryReceiptPrint({
    company,
    branch,
    lorryReceipt,
    urls,
}: Props) {
    return (
        <>
            <Head title={`Print LR ${lorryReceipt.lr_number}`} />
            <div className="mx-auto max-w-4xl space-y-6 p-6 print:p-0">
                <div className="flex items-center justify-between gap-3 print:hidden">
                    <Button asChild variant="secondary">
                        <Link href={urls.show}>Back</Link>
                    </Button>
                    <Button type="button" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>

                <header className="space-y-1 border-b border-border pb-4">
                    <p className="text-sm text-muted-foreground">
                        {company.name} · {branch.name}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Lorry Receipt {lorryReceipt.lr_number}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {lorryReceipt.lr_date} ·{' '}
                        {lorryReceipt.workflow_context.replaceAll('_', ' ')} ·{' '}
                        {lorryReceipt.status}
                    </p>
                </header>

                <section className="grid gap-3 text-sm md:grid-cols-2">
                    <p>
                        <span className="text-muted-foreground">
                            Loading Station:
                        </span>{' '}
                        {lorryReceipt.from_station_name ||
                            lorryReceipt.from_text ||
                            '—'}
                    </p>
                    <p>
                        <span className="text-muted-foreground">
                            Unloading Station:
                        </span>{' '}
                        {lorryReceipt.to_station_name ||
                            lorryReceipt.to_text ||
                            '—'}
                    </p>
                    <p>
                        <span className="text-muted-foreground">
                            Consignor:
                        </span>{' '}
                        {lorryReceipt.consignor_name || '—'}
                    </p>
                    <p>
                        <span className="text-muted-foreground">
                            Consignee:
                        </span>{' '}
                        {lorryReceipt.consignee_name || '—'}
                    </p>
                </section>

                <section>
                    <h2 className="mb-2 text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        Goods
                    </h2>
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-border">
                                <th className="py-2 font-medium">
                                    Description
                                </th>
                                <th className="py-2 font-medium">Pkgs</th>
                                <th className="py-2 font-medium">Weight</th>
                                <th className="py-2 font-medium">Freight</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lorryReceipt.goods.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b border-border/60"
                                >
                                    <td className="py-2">
                                        {row.goods_description || '—'}
                                    </td>
                                    <td className="py-2">
                                        {row.package_count || '—'}
                                    </td>
                                    <td className="py-2">
                                        {row.actual_weight || '—'} /{' '}
                                        {row.charged_weight || '—'}
                                    </td>
                                    <td className="py-2">
                                        {row.freight_amount}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                {lorryReceipt.eway_bills.length > 0 ? (
                    <section className="text-sm">
                        <h2 className="mb-2 font-semibold tracking-wide text-muted-foreground uppercase">
                            E-way bills
                        </h2>
                        <ul className="space-y-1">
                            {lorryReceipt.eway_bills.map((bill) => (
                                <li key={bill.id}>
                                    {bill.ewb_number} (until {bill.valid_until})
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                <section className="grid gap-2 border-t border-border pt-4 text-sm md:grid-cols-3">
                    <p>
                        Freight total:{' '}
                        <strong>{lorryReceipt.freight_total}</strong>
                    </p>
                    <p>
                        Additional charges:{' '}
                        <strong>{lorryReceipt.additional_charges_total}</strong>
                    </p>
                    <p>
                        Goods value:{' '}
                        <strong>{lorryReceipt.goods_value || '—'}</strong>
                    </p>
                </section>
            </div>
        </>
    );
}
