import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ClipboardList,
    FileText,
    Link2,
    Receipt,
    Scale,
    Truck,
    Wallet,
} from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { MetricCard } from '@/components/ui/metric-card';

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

const registerIcons: Record<string, LucideIcon> = {
    lorry_hires: Truck,
    vouchers_pending: Wallet,
    vouchers_all: FileText,
    memo_pod: ClipboardList,
    customer_invoices: Receipt,
    daily_payments: Wallet,
    transporter_balances: Scale,
    linked_bilty: Link2,
};

export default function BrokerRegistersIndex({ counts, registers }: Props) {
    const totalSignals =
        (counts.pending_lorry_hires ?? 0) +
        (counts.pending_vouchers ?? 0) +
        (counts.pending_customer_invoices ?? 0);

    return (
        <>
            <Head title="Broker Operational Registers" />
            <AppIndexPage className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Open operational signals"
                        value={totalSignals}
                        hint="Pending hires, vouchers, and unpaid customer invoices (where visible)"
                        icon={ClipboardList}
                        tone="blue"
                    />
                </div>

                <AppIndexListCard title="Broker Operational Registers">
                    <p className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-3 text-xs text-muted-foreground">
                        Read-focused shortcuts into existing Broker list
                        screens. Use filters on each register for pending
                        queues.
                    </p>
                    <ul className="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        {registers.map((register) => {
                            const Icon =
                                registerIcons[register.key] ?? ClipboardList;

                            return (
                                <li
                                    key={register.key}
                                    className="flex items-start gap-2 rounded-xl border border-border bg-card px-3 py-3 shadow-xs"
                                >
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="text-sm font-semibold text-foreground">
                                                {register.title}
                                            </span>
                                            {register.count !== null ? (
                                                <span className="shrink-0 rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-foreground">
                                                    {register.count}
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {register.description}
                                        </p>
                                    </div>
                                    <AppIconAction
                                        icon={Icon}
                                        label={`Open ${register.title}`}
                                        href={register.href}
                                        variant="info"
                                    />
                                </li>
                            );
                        })}
                    </ul>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
