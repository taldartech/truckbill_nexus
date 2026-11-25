import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    date: string;
    voucher_type: string;
    voucher_number: string;
    particulars: string;
    debit: string;
    credit: string;
    balance: string;
    balance_side: string;
};

type Props = {
    title: string;
    ledgerName: string;
    filters: { from_date: string | null; to_date: string | null };
    opening_debit: string;
    opening_credit: string;
    closing_debit: string;
    closing_credit: string;
    rows: Row[];
    urls: {
        index: string;
        cash_book: string;
        bank_book: string;
        vouchers: string;
        trial_balance: string;
    };
};

export default function AccountingCashBankBook({
    title,
    ledgerName,
    filters,
    opening_debit,
    opening_credit,
    closing_debit,
    closing_credit,
    rows,
    urls,
}: Props) {
    return (
        <>
            <Head title={title} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`${title} · ${ledgerName}`}
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.cash_book}>Cash Book</Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.bank_book}>Bank Book</Link>
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

                    <div className="border-b px-4 py-2 text-sm">
                        Opening Dr {opening_debit} · Opening Cr {opening_credit}{' '}
                        · Closing Dr {closing_debit} · Closing Cr{' '}
                        {closing_credit}
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[840px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Date
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Voucher
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Particulars
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Debit
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Credit
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, index) => (
                                    <tr
                                        key={`${row.voucher_number}-${index}`}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            {row.date}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.voucher_type}{' '}
                                            {row.voucher_number}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.particulars}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.debit}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.credit}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.balance} {row.balance_side}
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            className="px-3 py-6 text-muted-foreground"
                                            colSpan={6}
                                        >
                                            No movements in this period.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
