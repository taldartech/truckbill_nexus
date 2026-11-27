import { Head, router } from '@inertiajs/react';
import { AccountingBackAction } from '@/components/accounting/accounting-back-action';
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
    filters: { from_date: string; to_date: string };
    report: {
        income: Line[];
        expenses: Line[];
        total_income: string;
        total_expenses: string;
        net_profit: string;
    };
    urls: { index: string };
};

export default function AccountingProfitAndLoss({
    filters,
    report,
    urls,
}: Props) {
    return (
        <>
            <Head title="Profit and Loss" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Profit and Loss"
                    toolbar={<AccountingBackAction />}
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            router.get(
                                urls.index,
                                {
                                    from_date:
                                        (
                                            form.elements.namedItem(
                                                'from_date',
                                            ) as HTMLInputElement
                                        ).value || undefined,
                                    to_date:
                                        (
                                            form.elements.namedItem(
                                                'to_date',
                                            ) as HTMLInputElement
                                        ).value || undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="from_date">From</Label>
                            <Input
                                id="from_date"
                                name="from_date"
                                type="date"
                                defaultValue={filters.from_date}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="to_date">To</Label>
                            <Input
                                id="to_date"
                                name="to_date"
                                type="date"
                                defaultValue={filters.to_date}
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>

                    <div className="grid gap-6 p-4 md:grid-cols-2">
                        <section>
                            <h2 className="mb-2 text-sm font-semibold">
                                Income
                            </h2>
                            <table className="w-full text-left text-sm">
                                <tbody>
                                    {report.income.map((row) => (
                                        <tr
                                            key={row.ledger_id}
                                            className="border-b"
                                        >
                                            <td className="py-1.5">
                                                {row.ledger_name}
                                            </td>
                                            <td className="py-1.5 text-right">
                                                {row.amount}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="font-medium">
                                        <td className="py-2">Total Income</td>
                                        <td className="py-2 text-right">
                                            {report.total_income}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                        <section>
                            <h2 className="mb-2 text-sm font-semibold">
                                Expenses
                            </h2>
                            <table className="w-full text-left text-sm">
                                <tbody>
                                    {report.expenses.map((row) => (
                                        <tr
                                            key={row.ledger_id}
                                            className="border-b"
                                        >
                                            <td className="py-1.5">
                                                {row.ledger_name}
                                            </td>
                                            <td className="py-1.5 text-right">
                                                {row.amount}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="font-medium">
                                        <td className="py-2">Total Expenses</td>
                                        <td className="py-2 text-right">
                                            {report.total_expenses}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                    </div>
                    <div className="border-t px-4 py-3 text-sm font-semibold">
                        Net Profit / Loss: {report.net_profit}
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
