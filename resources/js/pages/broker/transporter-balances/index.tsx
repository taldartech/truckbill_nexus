import { Head, router } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { MetricCard } from '@/components/ui/metric-card';

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
    const columns = useMemo<DataTableColumn<EntryRow>[]>(
        () => [
            {
                id: 'created_at',
                header: 'Date',
                priority: 0,
                cell: (row) => row.created_at ?? '—',
            },
            {
                id: 'entry_type',
                header: 'Type',
                priority: 1,
                cell: (row) => row.entry_type,
            },
            {
                id: 'hire_number',
                header: 'Hire',
                priority: 2,
                cell: (row) => row.hire_number ?? '—',
            },
            {
                id: 'amount',
                header: 'Amount',
                priority: 2,
                cell: (row) => row.amount ?? '—',
            },
            {
                id: 'remark',
                header: 'Remark',
                priority: 3,
                className: 'max-w-xs truncate text-muted-foreground',
                cell: (row) => row.remark ?? '—',
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Transporter Book Balance" />
            <PageShell
                title="Transporter Book Balance"
                description="Outstanding balance summary per transporter. Select a transporter to view running balance and ledger entries."
            >
                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-3 shadow-xs"
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
                    <div className="min-w-0 flex-1 space-y-1 sm:max-w-xs">
                        <Label htmlFor="transporter_party_id">
                            Transporter
                        </Label>
                        <select
                            id="transporter_party_id"
                            name="transporter_party_id"
                            defaultValue={filters.transporter_party_id}
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
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
                        size="sm"
                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                    >
                        View statement
                    </Button>
                </form>

                {running_balance !== null ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <MetricCard
                            label="Running balance"
                            value={running_balance}
                            hint="For the selected transporter on this branch"
                            icon={Scale}
                            tone="blue"
                        />
                    </div>
                ) : null}

                <ResponsiveDataTable
                    caption="Transporter book balance entries"
                    columns={columns}
                    rows={entries.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No book balance entries yet"
                    emptyDescription="Voucher adjustments and advances appear after broker activity."
                />

                {entries.meta.total > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        Page {entries.meta.current_page} of{' '}
                        {entries.meta.last_page} · {entries.meta.total} total
                    </p>
                ) : null}
            </PageShell>
        </>
    );
}
