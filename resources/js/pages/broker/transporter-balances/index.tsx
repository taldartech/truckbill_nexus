import { Head, router } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type EntryRow = {
    id: string;
    entry_type: string;
    amount: string | null;
    hire_number: string | null;
    remark: string | null;
    created_at: string | null;
};

type Props = {
    filters: { transporter_party_id: string };
    transporters: Array<{ id: string; name: string }>;
    running_balance: string | null;
    entries: {
        data: EntryRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string };
};

export default function TransporterBalancesIndex({
    filters,
    transporters,
    running_balance,
    entries,
    urls,
}: Props) {
    return (
        <>
            <Head title="Transporter Book Balance" />
            <PageShell title="Transporter Book Balance">
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const form = event.currentTarget;
                        const transporter_party_id = (
                            form.elements.namedItem(
                                'transporter_party_id',
                            ) as HTMLSelectElement
                        ).value;
                        router.get(
                            urls.index,
                            {
                                transporter_party_id:
                                    transporter_party_id || undefined,
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <div className="space-y-1">
                        <Label htmlFor="transporter_party_id">
                            Transporter
                        </Label>
                        <select
                            id="transporter_party_id"
                            name="transporter_party_id"
                            defaultValue={filters.transporter_party_id}
                            className="h-9 min-w-[14rem] rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All transporters</option>
                            {transporters.map((party) => (
                                <option key={party.id} value={party.id}>
                                    {party.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button
                        type="submit"
                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                    >
                        View statement
                    </Button>
                </form>

                {running_balance !== null && (
                    <p className="text-sm font-medium">
                        Running balance: {running_balance}
                    </p>
                )}

                <div className="overflow-hidden rounded-lg border border-border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/40 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Date</th>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 font-medium">Hire</th>
                                <th className="px-4 py-3 font-medium">
                                    Amount
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Remark
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No book balance entries yet.
                                    </td>
                                </tr>
                            ) : (
                                entries.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-4 py-3">
                                            {row.created_at}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.entry_type}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.hire_number ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.amount ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.remark ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </>
    );
}
