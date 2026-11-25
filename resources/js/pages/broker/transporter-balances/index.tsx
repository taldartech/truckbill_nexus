import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';

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
    urls: { index: string; parties_options?: string };
};

export default function TransporterBalancesIndex({
    filters,
    transporters,
    running_balance,
    entries,
    urls,
}: Props) {
    const [transporterPartyId, setTransporterPartyId] = useState(
        filters.transporter_party_id,
    );

    return (
        <>
            <Head title="Transporter Book Balance" />
            <AppIndexPage>
                <AppIndexListCard title="Transporter Book Balance">
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                urls.index,
                                {
                                    transporter_party_id:
                                        transporterPartyId || undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="min-w-0 flex-1 space-y-1 sm:max-w-md">
                            <SearchablePartySelect
                                id="transporter_party_id"
                                name="transporter_party_id"
                                label="Transporter"
                                options={transporters}
                                searchUrl={urls.parties_options}
                                role="transporter"
                                value={transporterPartyId}
                                onValueChange={setTransporterPartyId}
                                emptyMessage="No eligible transporters in this branch."
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            View statement
                        </Button>
                    </AppIndexFilters>

                    {running_balance !== null ? (
                        <div className="border-b border-[#e5e7eb] bg-[#f0fdf4] px-4 py-3">
                            <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                Running balance
                            </p>
                            <p className="text-2xl font-bold text-slate-900 tabular-nums">
                                {running_balance}
                            </p>
                        </div>
                    ) : null}

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Hire</th>
                                    <th className="text-end">Amount</th>
                                    <th>Remark</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={5}>
                                            <p className="app-index-empty">
                                                No book balance entries yet.
                                                Select a transporter to view the
                                                statement.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    entries.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>{row.created_at ?? '—'}</td>
                                            <td>{row.entry_type}</td>
                                            <td>{row.hire_number ?? '—'}</td>
                                            <td className="text-end font-medium tabular-nums">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td className="max-w-xs truncate text-slate-500">
                                                {row.remark ?? '—'}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {entries.meta.total > 0 ? (
                        <p className="border-t border-[#e5e7eb] px-4 py-3 text-xs text-slate-500">
                            Page {entries.meta.current_page} of{' '}
                            {entries.meta.last_page} · {entries.meta.total}{' '}
                            total
                        </p>
                    ) : null}
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
