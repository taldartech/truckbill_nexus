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

type Props = {
    title?: string;
    filters: { as_of: string | null };
    rows: Array<{
        ledger_id: string;
        ledger_name: string;
        debit: string;
        credit: string;
        net: string;
        side: string;
    }>;
    urls: { index: string };
};

export default function AccountingOutstanding({
    title = 'Outstanding Control Accounts',
    filters,
    rows,
    urls,
}: Props) {
    return (
        <>
            <Head title={title} />
            <AppIndexPage>
                <AppIndexListCard
                    title={title}
                    toolbar={<AccountingBackAction />}
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            router.get(
                                urls.index,
                                {
                                    as_of:
                                        (
                                            form.elements.namedItem(
                                                'as_of',
                                            ) as HTMLInputElement
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
                                defaultValue={filters.as_of ?? ''}
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Ledger
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Closing Dr
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Closing Cr
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Net
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.ledger_id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {row.ledger_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.debit}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.credit}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.net} {row.side}
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            className="px-3 py-6 text-muted-foreground"
                                            colSpan={4}
                                        >
                                            No balances for this enquiry.
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
