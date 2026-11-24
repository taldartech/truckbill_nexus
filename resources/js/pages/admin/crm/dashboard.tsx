import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    Eye,
    Headphones,
    Plus,
    Users,
} from 'lucide-react';
import { useMemo } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
import {
    WorkflowProgress,
    type WorkflowStep,
} from '@/components/workflow-progress';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import { cn } from '@/lib/utils';

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

const FUNNEL_ORDER = ['new', 'contacted', 'qualified', 'converted'] as const;

function formatFunnelLabel(status: string, count: number): string {
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    return `${label} · ${count}`;
}

function buildPipelineSteps(
    leadsByStatus: Record<string, number>,
): WorkflowStep[] {
    let currentIdx = 0;
    for (let i = FUNNEL_ORDER.length - 1; i >= 0; i--) {
        const count = leadsByStatus[FUNNEL_ORDER[i]] ?? 0;
        if (count > 0) {
            currentIdx = i;
            break;
        }
    }

    return FUNNEL_ORDER.map((status, position) => {
        const count = leadsByStatus[status] ?? 0;
        let stepStatus: WorkflowStep['status'] = 'upcoming';
        if (position < currentIdx) {
            stepStatus = count > 0 ? 'complete' : 'upcoming';
        } else if (position === currentIdx) {
            stepStatus = count > 0 ? 'current' : 'upcoming';
        }

        return {
            id: status,
            label: formatFunnelLabel(status, count),
            status: stepStatus,
        };
    });
}

function ActivityTimeline({
    items,
    emptyLabel,
    variant,
}: {
    items: {
        id: string;
        primary: string;
        secondary?: string | null;
        status: string;
        href: string;
        actionLabel: string;
        icon: typeof Eye;
    }[];
    emptyLabel: string;
    variant: 'lead' | 'ticket';
}) {
    return (
        <ul className="relative space-y-0">
            {items.length === 0 ? (
                <li className="text-sm text-muted-foreground">{emptyLabel}</li>
            ) : (
                items.map((item, index) => (
                    <li key={item.id} className="relative flex gap-3 pb-4">
                        {index < items.length - 1 ? (
                            <span
                                className="absolute top-3 left-[11px] h-[calc(100%-0.25rem)] w-px bg-border"
                                aria-hidden
                            />
                        ) : null}
                        <span
                            className={cn(
                                'relative z-1 mt-1 size-[22px] shrink-0 rounded-full ring-2 ring-card',
                                variant === 'lead'
                                    ? 'bg-[color-mix(in_srgb,var(--brand-green)_85%,white)]'
                                    : 'bg-[color-mix(in_srgb,var(--brand-blue)_85%,white)]',
                            )}
                            aria-hidden
                        />
                        <div className="min-w-0 flex-1 space-y-1 rounded-lg border border-border bg-card px-3 py-2 shadow-xs">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className="text-sm font-medium text-foreground">
                                    {item.primary}
                                </p>
                                <WorkspaceStatusBadge status={item.status} />
                            </div>
                            {item.secondary ? (
                                <p className="text-xs text-muted-foreground">
                                    {item.secondary}
                                </p>
                            ) : null}
                            <AppIconAction
                                icon={item.icon}
                                label={item.actionLabel}
                                href={item.href}
                                variant="info"
                                size="sm"
                            />
                        </div>
                    </li>
                ))
            )}
        </ul>
    );
}

export default function CrmDashboard({ metrics, urls }: Props) {
    const scopeLabel =
        metrics.scope === 'team' ? 'Team workspace' : 'Assigned to me';

    const pipelineSteps = useMemo(
        () => buildPipelineSteps(metrics.leads_by_status),
        [metrics.leads_by_status],
    );

    const terminalLeadCount =
        (metrics.leads_by_status.disqualified ?? 0) +
        (metrics.leads_by_status.lost ?? 0);

    const ticketUrgency: 'calm' | 'watch' | 'critical' =
        metrics.tickets_overdue > 0
            ? 'critical'
            : metrics.tickets_open > 0
              ? 'watch'
              : 'calm';

    return (
        <>
            <Head title="CRM Dashboard" />
            <PageShell
                title="CRM Dashboard"
                description={`Pipeline overview · ${scopeLabel}`}
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href={urls.leads}>Leads</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.tickets}>Tickets</Link>
                        </Button>
                        <Button asChild variant="default">
                            <Link href={urls.create_lead}>New lead</Link>
                        </Button>
                    </>
                }
            >
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        label="Total leads"
                        value={metrics.leads_total}
                        icon={Users}
                        tone="green"
                    />
                    <MetricCard
                        label="Pending follow-ups"
                        value={metrics.pending_follow_ups}
                        icon={Clock}
                        tone="blue"
                    />
                    <MetricCard
                        label="Overdue follow-ups"
                        value={metrics.overdue_follow_ups}
                        icon={AlertTriangle}
                        tone={
                            metrics.overdue_follow_ups > 0 ? 'amber' : 'neutral'
                        }
                    />
                    <MetricCard
                        label="Open tickets"
                        value={metrics.tickets_open}
                        hint={`${metrics.tickets_overdue} overdue`}
                        icon={Headphones}
                        tone={metrics.tickets_overdue > 0 ? 'amber' : 'blue'}
                    />
                </div>

                <div className="grid gap-5 xl:grid-cols-12">
                    <div className="space-y-4 xl:col-span-8">
                        <section className="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs md:p-5">
                            <div className="flex flex-wrap items-end justify-between gap-2">
                                <div>
                                    <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Lead funnel
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Intake through conversion ·{' '}
                                        {terminalLeadCount > 0
                                            ? `${terminalLeadCount} closed out (disqualified / lost)`
                                            : 'no terminal exits'}
                                    </p>
                                </div>
                                <Button asChild size="sm" variant="secondary">
                                    <Link href={urls.leads}>Open pipeline</Link>
                                </Button>
                            </div>
                            <WorkflowProgress
                                aria-label="Lead pipeline funnel"
                                steps={pipelineSteps}
                                className="lg:grid-cols-4"
                            />
                        </section>

                        <div className="grid gap-5 lg:grid-cols-2">
                            <section className="rounded-xl border border-border bg-card p-4 shadow-xs md:p-5">
                                <div className="mb-4 flex items-center justify-between gap-2">
                                    <h2 className="text-sm font-semibold text-foreground">
                                        Recent leads
                                    </h2>
                                    <Button asChild variant="link" size="sm">
                                        <Link href={urls.leads}>View all</Link>
                                    </Button>
                                </div>
                                <ActivityTimeline
                                    variant="lead"
                                    emptyLabel="No leads yet."
                                    items={metrics.recent_leads.map((lead) => ({
                                        id: lead.id,
                                        primary: lead.contact_name,
                                        secondary: lead.created_at,
                                        status: lead.status,
                                        href: `/admin/crm/leads/${lead.id}`,
                                        actionLabel: 'View lead',
                                        icon: Eye,
                                    }))}
                                />
                            </section>

                            <section className="rounded-xl border border-border bg-card p-4 shadow-xs md:p-5">
                                <div className="mb-4 flex items-center justify-between gap-2">
                                    <h2 className="text-sm font-semibold text-foreground">
                                        Recent tickets
                                    </h2>
                                    <Button asChild size="sm" variant="default">
                                        <Link href={urls.create_ticket}>
                                            <Plus
                                                className="size-4"
                                                aria-hidden
                                            />
                                            New ticket
                                        </Link>
                                    </Button>
                                </div>
                                <ActivityTimeline
                                    variant="ticket"
                                    emptyLabel="No tickets yet."
                                    items={metrics.recent_tickets.map(
                                        (ticket) => ({
                                            id: ticket.id,
                                            primary: ticket.subject,
                                            secondary: ticket.created_at,
                                            status: ticket.status,
                                            href: `/admin/crm/tickets/${ticket.id}`,
                                            actionLabel: 'View ticket',
                                            icon: Headphones,
                                        }),
                                    )}
                                />
                            </section>
                        </div>
                    </div>

                    <aside className="space-y-4 xl:col-span-4">
                        <section
                            className={cn(
                                'rounded-xl border p-4 shadow-xs md:p-5',
                                ticketUrgency === 'critical'
                                    ? 'border-destructive/40 bg-destructive/5'
                                    : ticketUrgency === 'watch'
                                      ? 'border-amber-500/40 bg-amber-500/5'
                                      : 'border-border bg-card',
                            )}
                        >
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Ticket urgency
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Queue pressure from open and overdue work.
                            </p>
                            <dl className="mt-4 grid grid-cols-2 gap-3">
                                <div className="rounded-lg border border-border bg-background/80 px-3 py-2">
                                    <dt className="text-xs text-muted-foreground">
                                        Open
                                    </dt>
                                    <dd className="text-2xl font-semibold text-[#2D8CFF] tabular-nums">
                                        {metrics.tickets_open}
                                    </dd>
                                </div>
                                <div className="rounded-lg border border-border bg-background/80 px-3 py-2">
                                    <dt className="text-xs text-muted-foreground">
                                        Overdue
                                    </dt>
                                    <dd
                                        className={cn(
                                            'text-2xl font-semibold tabular-nums',
                                            metrics.tickets_overdue > 0
                                                ? 'text-destructive'
                                                : 'text-foreground',
                                        )}
                                    >
                                        {metrics.tickets_overdue}
                                    </dd>
                                </div>
                            </dl>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <Link href={urls.tickets}>Queue</Link>
                                </Button>
                                <Button asChild size="sm" variant="default">
                                    <Link href={urls.create_ticket}>
                                        Create ticket
                                    </Link>
                                </Button>
                            </div>
                        </section>

                        <section className="rounded-xl border border-border bg-card p-4 shadow-xs md:p-5">
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Follow-up load
                            </h2>
                            <ul className="mt-3 space-y-2 text-sm">
                                <li className="flex items-center justify-between rounded-lg border border-border bg-muted/20 px-3 py-2">
                                    <span className="text-muted-foreground">
                                        Pending
                                    </span>
                                    <span className="font-semibold tabular-nums">
                                        {metrics.pending_follow_ups}
                                    </span>
                                </li>
                                <li className="flex items-center justify-between rounded-lg border border-border bg-muted/20 px-3 py-2">
                                    <span className="text-muted-foreground">
                                        Overdue
                                    </span>
                                    <span
                                        className={cn(
                                            'font-semibold tabular-nums',
                                            metrics.overdue_follow_ups > 0
                                                ? 'text-amber-700'
                                                : '',
                                        )}
                                    >
                                        {metrics.overdue_follow_ups}
                                    </span>
                                </li>
                            </ul>
                        </section>

                        {terminalLeadCount > 0 ? (
                            <section className="rounded-xl border border-dashed border-border bg-muted/20 p-4 text-sm md:p-5">
                                <h2 className="font-medium text-foreground">
                                    Pipeline exits
                                </h2>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {(['disqualified', 'lost'] as const).map(
                                        (status) => (
                                            <ReviewStatusChip
                                                key={status}
                                                status={status}
                                                count={
                                                    metrics.leads_by_status[
                                                        status
                                                    ] ?? 0
                                                }
                                            />
                                        ),
                                    )}
                                </div>
                            </section>
                        ) : null}
                    </aside>
                </div>
            </PageShell>
        </>
    );
}

function ReviewStatusChip({
    status,
    count,
}: {
    status: string;
    count: number;
}) {
    if (count === 0) {
        return null;
    }

    return (
        <div className="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-1.5">
            <WorkspaceStatusBadge status={status} />
            <span className="text-sm font-semibold tabular-nums">{count}</span>
        </div>
    );
}
