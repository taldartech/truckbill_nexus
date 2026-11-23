import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Metrics = {
    scope: string;
    leads_total: number;
    leads_by_status: Record<string, number>;
    pending_follow_ups: number;
    overdue_follow_ups: number;
    tickets_open: number;
    tickets_overdue: number;
    recent_leads: {
        id: string;
        contact_name: string;
        status: string;
        created_at: string | null;
    }[];
    recent_tickets: {
        id: string;
        subject: string;
        status: string;
        created_at: string | null;
    }[];
};

type Props = {
    metrics: Metrics;
    urls: {
        leads: string;
        tickets: string;
        create_lead: string;
        create_ticket: string;
    };
};

export default function CrmDashboard({ metrics, urls }: Props) {
    return (
        <>
            <Head title="CRM Dashboard" />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            CRM Dashboard
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Scope: {metrics.scope === 'team' ? 'Team' : 'Assigned to me'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.leads}>Leads</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.tickets}>Tickets</Link>
                        </Button>
                        <Button asChild>
                            <Link href={urls.create_lead}>New lead</Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard label="Leads" value={metrics.leads_total} />
                    <MetricCard
                        label="Pending follow-ups"
                        value={metrics.pending_follow_ups}
                    />
                    <MetricCard
                        label="Overdue follow-ups"
                        value={metrics.overdue_follow_ups}
                        accent={metrics.overdue_follow_ups > 0}
                    />
                    <MetricCard
                        label="Open tickets"
                        value={metrics.tickets_open}
                        hint={`${metrics.tickets_overdue} overdue`}
                    />
                </div>

                <section className="rounded-xl border border-border p-4">
                    <h2 className="mb-3 font-semibold">Leads by status</h2>
                    <div className="flex flex-wrap gap-2 text-sm">
                        {Object.entries(metrics.leads_by_status).map(
                            ([status, count]) => (
                                <span
                                    key={status}
                                    className="rounded-full border border-border px-3 py-1"
                                >
                                    {status}: {count}
                                </span>
                            ),
                        )}
                    </div>
                </section>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-xl border border-border p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-semibold">Recent leads</h2>
                            <Link
                                href={urls.leads}
                                className="text-sm text-[#2D8CFF] underline-offset-4 hover:underline"
                            >
                                View all
                            </Link>
                        </div>
                        <ul className="space-y-2 text-sm">
                            {metrics.recent_leads.length === 0 ? (
                                <li className="text-muted-foreground">
                                    No leads yet.
                                </li>
                            ) : (
                                metrics.recent_leads.map((lead) => (
                                    <li key={lead.id}>
                                        <Link
                                            href={`/admin/crm/leads/${lead.id}`}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {lead.contact_name}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {lead.status}
                                        </span>
                                    </li>
                                ))
                            )}
                        </ul>
                    </section>

                    <section className="rounded-xl border border-border p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-semibold">Recent tickets</h2>
                            <Button asChild size="sm" variant="outline">
                                <Link href={urls.create_ticket}>New ticket</Link>
                            </Button>
                        </div>
                        <ul className="space-y-2 text-sm">
                            {metrics.recent_tickets.length === 0 ? (
                                <li className="text-muted-foreground">
                                    No tickets yet.
                                </li>
                            ) : (
                                metrics.recent_tickets.map((ticket) => (
                                    <li key={ticket.id}>
                                        <Link
                                            href={`/admin/crm/tickets/${ticket.id}`}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {ticket.subject}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {ticket.status}
                                        </span>
                                    </li>
                                ))
                            )}
                        </ul>
                    </section>
                </div>
            </div>
        </>
    );
}

function MetricCard({
    label,
    value,
    hint,
    accent = false,
}: {
    label: string;
    value: number;
    hint?: string;
    accent?: boolean;
}) {
    return (
        <div className="rounded-xl border border-border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={`mt-1 text-2xl font-semibold ${accent ? 'text-amber-700' : ''}`}
            >
                {value}
            </p>
            {hint ? (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}
