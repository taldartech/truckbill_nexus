import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Line = { ledger_id: string; ledger_name: string; amount: string };

type Props = {
    filters: { as_of: string };
    report: {
        assets: Line[];
        liabilities: Line[];
        equity: Line[];
        total_assets: string;
        total_liabilities: string;
        total_equity: string;
        net_profit: string;
        balanced: boolean;
    };
    urls: {
        index: string;
        trial_balance: string;
        profit_and_loss: string;
        vouchers: string;
    };
};

function Section({
    title,
    rows,
    totalLabel,
    total,
}: {
    title: string;
    rows: Line[];
    totalLabel: string;
    total: string;
}) {
    return (
        <section>
            <h2 className="mb-2 text-sm font-semibold">{title}</h2>
            <table className="w-full text-left text-sm">
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.ledger_id} className="border-b">
                            <td className="py-1.5">{row.ledger_name}</td>
                            <td className="py-1.5 text-right">{row.amount}</td>
                        </tr>
                    ))}
                    <tr className="font-medium">
                        <td className="py-2">{totalLabel}</td>
                        <td className="py-2 text-right">{total}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    );
}

export default function AccountingBalanceSheet({ filters, report, urls }: Props) {
    return (
        <>
            <Head title="Balance Sheet" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Balance Sheet"
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.trial_balance}>Trial Balance</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.profit_and_loss}>Profit & Loss</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.vouchers}>Day Book</Link>
                            </Button>
                        </div>
                    }
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            router.get(
                                urls.index,
                                {
                                    as_of: (
                                        form.elements.namedItem('as_of') as HTMLInputElement
                                    ).value || undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="as_of">As of</Label>
                            <Input
                                id="as_of"
                                name="as_of"
                                type="date"
                                defaultValue={filters.as_of}
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>

                    <div className="grid gap-6 p-4 md:grid-cols-2">
                        <Section
                            title="Assets"
                            rows={report.assets}
                            totalLabel="Total Assets"
                            total={report.total_assets}
                        />
                        <div className="space-y-6">
                            <Section
                                title="Liabilities"
                                rows={report.liabilities}
                                totalLabel="Total Liabilities"
                                total={report.total_liabilities}
                            />
                            <Section
                                title="Equity"
                                rows={report.equity}
                                totalLabel="Total Equity"
                                total={report.total_equity}
                            />
                        </div>
                    </div>
                    <div className="border-t px-4 py-3 text-sm font-semibold">
                        Accounting equation{' '}
                        {report.balanced ? (
                            <span className="text-emerald-700">(balanced)</span>
                        ) : (
                            <span className="text-destructive">(out of balance)</span>
                        )}
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
