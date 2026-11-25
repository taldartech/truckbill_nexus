import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    bootstrap: { period_label: string | null };
    filters: { from_date: string; to_date: string; voucher_type: string };
    voucherTypeOptions: Array<{ value: string; label: string }>;
    permissions: { can_create: boolean; can_report: boolean };
    vouchers: {
        data: Array<{
            id: string;
            voucher_number: string;
            voucher_date: string | null;
            voucher_type_label: string;
            narration: string | null;
            total_debit: string;
            urls: { show: string };
        }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: {
        index: string;
        create: string;
        ledgers: string;
        trial_balance: string;
        profit_and_loss: string;
        balance_sheet: string;
        cash_book: string;
        bank_book: string;
    };
};

export default function AccountingVouchersIndex({
    bootstrap,
    filters,
    voucherTypeOptions,
    permissions,
    vouchers,
    urls,
}: Props) {
    return (
        <>
            <Head title="Day Book" />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Day Book${bootstrap.period_label ? ` · ${bootstrap.period_label}` : ''}`}
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.ledgers}>Ledgers</Link>
                            </Button>
                            {permissions.can_report ? (
                                <>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={urls.trial_balance}>
                                            Trial Balance
                                        </Link>
                                    </Button>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={urls.profit_and_loss}>
                                            P&L
                                        </Link>
                                    </Button>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={urls.balance_sheet}>
                                            Balance Sheet
                                        </Link>
                                    </Button>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={urls.cash_book}>
                                            Cash Book
                                        </Link>
                                    </Button>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={urls.bank_book}>
                                            Bank Book
                                        </Link>
                                    </Button>
                                </>
                            ) : null}
                            {permissions.can_create ? (
                                <Button
                                    asChild
                                    size="sm"
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    <Link href={urls.create}>New Voucher</Link>
                                </Button>
                            ) : null}
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
                                    voucher_type:
                                        (
                                            form.elements.namedItem(
                                                'voucher_type',
                                            ) as HTMLSelectElement
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
                        <div className="space-y-1">
                            <Label htmlFor="voucher_type">Type</Label>
                            <select
                                id="voucher_type"
                                name="voucher_type"
                                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                defaultValue={filters.voucher_type}
                            >
                                <option value="">All</option>
                                {voucherTypeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[800px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Date
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Voucher
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Narration
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {vouchers.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No posted vouchers in this range.
                                        </td>
                                    </tr>
                                ) : (
                                    vouchers.data.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-3 py-2">
                                                {row.voucher_date}
                                            </td>
                                            <td className="px-3 py-2 font-medium">
                                                {row.voucher_number}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.voucher_type_label}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.narration}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.total_debit}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link href={row.urls.show}>
                                                        View
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-4 py-3">
                        <TablePagination
                            baseUrl={urls.index}
                            meta={vouchers.meta}
                            query={{
                                from_date: filters.from_date || undefined,
                                to_date: filters.to_date || undefined,
                                voucher_type: filters.voucher_type || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
