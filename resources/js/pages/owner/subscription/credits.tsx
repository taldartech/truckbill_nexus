import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
import { formatDisplayDateSmart } from '@/lib/dates';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    ledgers: {
        id: string;
        ledger_type: string;
        service_key: string | null;
        balance: string;
        unit: string;
        is_cash_wallet: boolean;
    }[];
    entries: {
        id: string;
        ledger_id: string;
        amount: string;
        entry_type: string;
        reason: string;
        created_at: string | null;
    }[];
    quotas: {
        id: string;
        quota_type: string;
        included_units: string;
        consumed_units: string;
        available_units: string;
        period_start: string | null;
        period_end: string | null;
        percent_used: number;
        alert: boolean;
        block: boolean;
    }[];
    presentation_note: string;
    links: { overview: string };
};

export default function SubscriptionCredits({
    company,
    ownerPanelScope,
    ledgers,
    entries,
    quotas,
    presentation_note,
    links,
}: Props) {
    const entryColumns = useMemo<DataTableColumn<(typeof entries)[number]>[]>(
        () => [
            {
                id: 'when',
                header: 'When',
                priority: 0,
                cell: (row) => formatDisplayDateSmart(row.created_at),
            },
            {
                id: 'type',
                header: 'Type',
                priority: 1,
                cell: (row) => row.entry_type,
            },
            {
                id: 'amount',
                header: 'Amount',
                priority: 2,
                className: 'font-mono',
                cell: (row) => row.amount,
            },
            {
                id: 'reason',
                header: 'Reason',
                priority: 2,
                cell: (row) => row.reason,
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Credits · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Credits & quotas"
            >
                <p className="text-sm text-muted-foreground">
                    {presentation_note}
                </p>
                <Button asChild variant="outline" className="self-start">
                    <Link href={links.overview}>Back to overview</Link>
                </Button>
                <section className="space-y-3">
                    <h2 className="font-semibold">Broker / Partload quotas</h2>
                    {quotas.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No quota periods allocated yet.
                        </p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {quotas.map((quota) => (
                                <li
                                    key={quota.id}
                                    className="rounded-xl border border-border p-4 text-sm"
                                >
                                    <p className="font-medium">
                                        {quota.quota_type}
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        Included {quota.included_units} ·
                                        Consumed {quota.consumed_units} ·
                                        Available {quota.available_units}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {quota.period_start || '—'} →{' '}
                                        {quota.period_end || '—'} ·{' '}
                                        {quota.percent_used}%
                                        {quota.alert ? ' · alert' : ''}
                                        {quota.block ? ' · block' : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <h2 className="font-semibold">Service credit balances</h2>
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {ledgers.map((ledger) => (
                        <li
                            key={ledger.id}
                            className="rounded-xl border border-border p-4"
                        >
                            <p className="text-sm text-muted-foreground">
                                {ledger.unit}
                            </p>
                            <p className="text-2xl font-semibold">
                                {ledger.balance}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {ledger.ledger_type}
                                {ledger.service_key
                                    ? ` · ${ledger.service_key}`
                                    : ''}
                            </p>
                            {ledger.is_cash_wallet ? null : (
                                <p className="mt-2 text-xs">Non-cash ledger</p>
                            )}
                        </li>
                    ))}
                </ul>
                <section className="space-y-3">
                    <h2 className="font-semibold">Ledger history</h2>
                    <ResponsiveDataTable
                        caption="Ledger history"
                        columns={entryColumns}
                        rows={entries}
                        rowKey={(row) => row.id}
                        emptyTitle="No entries yet"
                        emptyDescription="Credit ledger movements will appear here."
                    />
                </section>
            </CompanyOwnerPageShell>
        </>
    );
}
