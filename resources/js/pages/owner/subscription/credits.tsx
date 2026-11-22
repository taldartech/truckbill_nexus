import { Head, Link } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import { Button } from '@/components/ui/button';

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
    presentation_note: string;
    links: { overview: string };
};

export default function SubscriptionCredits({
    company,
    ledgers,
    entries,
    presentation_note,
    links,
}: Props) {
    return (
        <>
            <Head title={`Credits · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Credits & quotas
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Credits & quotas
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {presentation_note}
                        </p>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>
                <Button asChild variant="outline" className="self-start">
                    <Link href={links.overview}>Back to overview</Link>
                </Button>
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
                    {entries.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No entries yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-border">
                            <table className="w-full min-w-[560px] text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            When
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Amount
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Reason
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                {entry.created_at || '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {entry.entry_type}
                                            </td>
                                            <td className="px-4 py-3 font-mono">
                                                {entry.amount}
                                            </td>
                                            <td className="px-4 py-3">
                                                {entry.reason}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}
