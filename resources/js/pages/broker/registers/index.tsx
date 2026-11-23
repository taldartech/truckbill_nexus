import { Head, Link } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { ClipboardList } from 'lucide-react';

type RegisterLink = {
    key: string;
    title: string;
    description: string;
    href: string;
    count: number | null;
};

type Props = {
    counts: {
        pending_lorry_hires: number | null;
        pending_vouchers: number | null;
        memo_pod_queue: number | null;
        pending_customer_invoices: number | null;
        linked_bilty_groups: number | null;
    };
    registers: RegisterLink[];
    urls: { index: string };
};

export default function BrokerRegistersIndex({ counts, registers }: Props) {
    const totalSignals =
        (counts.pending_lorry_hires ?? 0) +
        (counts.pending_vouchers ?? 0) +
        (counts.pending_customer_invoices ?? 0);

    return (
        <>
            <Head title="Broker Operational Registers" />
            <PageShell
                title="Broker operational registers"
                description="Read-focused shortcuts into existing Broker list screens. Use filters on each register for pending queues."
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Open operational signals"
                        value={totalSignals}
                        hint="Pending hires, vouchers, and unpaid customer invoices (where visible)"
                        icon={ClipboardList}
                        tone="blue"
                    />
                </div>

                <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {registers.map((register) => (
                        <li key={register.key}>
                            <Link
                                href={register.href}
                                className="flex h-full flex-col gap-2 rounded-xl border border-border bg-card px-4 py-4 shadow-xs transition-colors hover:border-primary/40 hover:bg-muted/30"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <span className="text-sm font-semibold text-foreground">
                                        {register.title}
                                    </span>
                                    {register.count !== null ? (
                                        <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-foreground">
                                            {register.count}
                                        </span>
                                    ) : null}
                                </div>
                                <span className="text-xs text-muted-foreground">
                                    {register.description}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </PageShell>
        </>
    );
}
