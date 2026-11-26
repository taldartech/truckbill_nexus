import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type BalanceRow = {
    key: string;
    label: string;
    operational_amount: string;
    gl_amount: string;
    variance: string;
    reconciled: boolean;
    explanation: string;
};

type Props = {
    filters: { from_date: string | null; to_date: string | null };
    reconciliation: {
        balances: BalanceRow[];
        profitability: {
            operational_revenue: string;
            gl_income: string;
            operational_expenses: string;
            gl_expenses: string;
            operational_profit: string;
            gl_net_profit: string;
            profit_variance: string;
            explanation: string;
        };
        unposted_operational_count: number;
        cutover_date: string | null;
    };
    urls: {
        index: string;
        activation: string;
        trial_balance: string;
        profit_and_loss: string;
        vouchers: string;
    };
};

export default function AccountingReconciliation({
    filters,
    reconciliation,
    urls,
}: Props) {
    const [fromDate, setFromDate] = useState(filters.from_date ?? '');
    const [toDate, setToDate] = useState(filters.to_date ?? '');

    return (
        <>
            <Head title="Operational vs GL Reconciliation" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Operational vs General Ledger Reconciliation"
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.activation}>
                                    Activation & Catch-up
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.profit_and_loss}>P&amp;L</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.trial_balance}>
                                    Trial Balance
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.vouchers}>Day Book</Link>
                            </Button>
                        </div>
                    }
                >
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                urls.index,
                                {
                                    from_date: fromDate || undefined,
                                    to_date: toDate || undefined,
                                },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="flex flex-wrap items-end gap-3 border-b p-4"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="from_date">From Date</Label>
                            <Input
                                id="from_date"
                                type="date"
                                value={fromDate}
                                onChange={(e) => setFromDate(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="to_date">To Date</Label>
                            <Input
                                id="to_date"
                                type="date"
                                value={toDate}
                                onChange={(e) => setToDate(e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="secondary" size="sm">
                            Refresh
                        </Button>
                        {reconciliation.unposted_operational_count > 0 ? (
                            <Badge variant="destructive">
                                {reconciliation.unposted_operational_count}{' '}
                                unposted event(s)
                            </Badge>
                        ) : (
                            <Badge variant="secondary">
                                Cutover {reconciliation.cutover_date ?? 'N/A'} ·
                                All operational events linked
                            </Badge>
                        )}
                    </form>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Control Balance</th>
                                    <th>Operational</th>
                                    <th>General Ledger</th>
                                    <th>Variance</th>
                                    <th>Status</th>
                                    <th>Explanation</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reconciliation.balances.map((row) => (
                                    <tr key={row.key}>
                                        <td className="font-medium">
                                            {row.label}
                                        </td>
                                        <td>₹{row.operational_amount}</td>
                                        <td>₹{row.gl_amount}</td>
                                        <td className="font-medium">
                                            ₹{row.variance}
                                        </td>
                                        <td>
                                            <Badge
                                                variant={
                                                    row.reconciled
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                            >
                                                {row.reconciled
                                                    ? 'Reconciled'
                                                    : 'Variance'}
                                            </Badge>
                                        </td>
                                        <td className="text-xs text-muted-foreground">
                                            {row.explanation}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="grid gap-3 border-t p-4 md:grid-cols-3">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Operational Revenue vs GL Income
                            </div>
                            <div className="font-medium">
                                ₹
                                {
                                    reconciliation.profitability
                                        .operational_revenue
                                }{' '}
                                / ₹{reconciliation.profitability.gl_income}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Operational Expenses vs GL Expenses
                            </div>
                            <div className="font-medium">
                                ₹
                                {
                                    reconciliation.profitability
                                        .operational_expenses
                                }{' '}
                                / ₹{reconciliation.profitability.gl_expenses}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Operational vs GL Net Profit (Variance)
                            </div>
                            <div className="font-medium">
                                ₹
                                {
                                    reconciliation.profitability
                                        .operational_profit
                                }{' '}
                                vs ₹{reconciliation.profitability.gl_net_profit}{' '}
                                (₹
                                {reconciliation.profitability.profit_variance})
                            </div>
                        </div>
                        <p className="text-xs text-muted-foreground md:col-span-3">
                            {reconciliation.profitability.explanation}
                        </p>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
