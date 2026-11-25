import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    filters: { from_date: string | null; to_date: string | null };
    rows: Array<{
        ledger_id: string;
        ledger_name: string;
        group_name: string;
        opening_debit: string;
        opening_credit: string;
        period_debit: string;
        period_credit: string;
        closing_debit: string;
        closing_credit: string;
    }>;
    totals: {
        closing_debit: string;
        closing_credit: string;
        balanced: boolean;
    };
    urls: { index: string; vouchers: string };
};

export default function AccountingTrialBalance({
    filters,
    rows,
    totals,
    urls,
}: Props) {
    return (
        <>
            <Head title="Trial Balance" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Trial Balance"
                    toolbar={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.vouchers}>Day Book</Link>
                        </Button>
                    }
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            router.get(
                                urls.index,
                                {
                                    from_date: (
                                        form.elements.namedItem('from_date') as HTMLInputElement
                                    ).value || undefined,
                                    to_date: (
                                        form.elements.namedItem('to_date') as HTMLInputElement
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
                                defaultValue={filters.from_date ?? ''}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="to_date">To</Label>
                            <Input
                                id="to_date"
                                name="to_date"
                                type="date"
                                defaultValue={filters.to_date ?? ''}
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[960px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Ledger</th>
                                    <th className="px-3 py-2 font-medium">Group</th>
                                    <th className="px-3 py-2 font-medium">Opening Dr</th>
                                    <th className="px-3 py-2 font-medium">Opening Cr</th>
                                    <th className="px-3 py-2 font-medium">Period Dr</th>
                                    <th className="px-3 py-2 font-medium">Period Cr</th>
                                    <th className="px-3 py-2 font-medium">Closing Dr</th>
                                    <th className="px-3 py-2 font-medium">Closing Cr</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.ledger_id} className="border-b last:border-0">
                                        <td className="px-3 py-2 font-medium">{row.ledger_name}</td>
                                        <td className="px-3 py-2">{row.group_name}</td>
                                        <td className="px-3 py-2">{row.opening_debit}</td>
                                        <td className="px-3 py-2">{row.opening_credit}</td>
                                        <td className="px-3 py-2">{row.period_debit}</td>
                                        <td className="px-3 py-2">{row.period_credit}</td>
                                        <td className="px-3 py-2">{row.closing_debit}</td>
                                        <td className="px-3 py-2">{row.closing_credit}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t font-medium">
                                    <td className="px-3 py-2" colSpan={6}>
                                        Totals{' '}
                                        {totals.balanced ? (
                                            <span className="text-emerald-700">(balanced)</span>
                                        ) : (
                                            <span className="text-destructive">(out of balance)</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{totals.closing_debit}</td>
                                    <td className="px-3 py-2">{totals.closing_credit}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
