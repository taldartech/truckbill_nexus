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
    ledger: { id: string; name: string };
    opening_debit: string;
    opening_credit: string;
    closing_debit: string;
    closing_credit: string;
    rows: Array<{
        date: string;
        voucher_type: string;
        voucher_number: string;
        particulars: string;
        debit: string;
        credit: string;
        balance: string;
        balance_side: string;
    }>;
    urls: { index: string; show: string };
};

export default function AccountingLedgerShow({
    filters,
    ledger,
    opening_debit,
    opening_credit,
    closing_debit,
    closing_credit,
    rows,
    urls,
}: Props) {
    return (
        <>
            <Head title={`Ledger · ${ledger.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Ledger · ${ledger.name}`}
                    toolbar={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.index}>Ledgers</Link>
                        </Button>
                    }
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            router.get(
                                urls.show,
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
                    <div className="grid gap-3 border-b p-4 md:grid-cols-2">
                        <div>
                            Opening Dr {opening_debit} / Cr {opening_credit}
                        </div>
                        <div>
                            Closing Dr {closing_debit} / Cr {closing_credit}
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Date</th>
                                    <th className="px-3 py-2 font-medium">Voucher</th>
                                    <th className="px-3 py-2 font-medium">Particulars</th>
                                    <th className="px-3 py-2 font-medium">Debit</th>
                                    <th className="px-3 py-2 font-medium">Credit</th>
                                    <th className="px-3 py-2 font-medium">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-3 py-8 text-center text-muted-foreground">
                                            No movements in this period.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row, index) => (
                                        <tr key={index} className="border-b last:border-0">
                                            <td className="px-3 py-2">{row.date}</td>
                                            <td className="px-3 py-2">
                                                {row.voucher_type} {row.voucher_number}
                                            </td>
                                            <td className="px-3 py-2">{row.particulars}</td>
                                            <td className="px-3 py-2">{row.debit}</td>
                                            <td className="px-3 py-2">{row.credit}</td>
                                            <td className="px-3 py-2">
                                                {row.balance} {row.balance_side}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
