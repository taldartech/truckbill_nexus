import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    Eye,
    Headphones,
    Plus,
    Users,
} from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection, PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';

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
    const scopeLabel =
        metrics.scope === 'team' ? 'Team workspace' : 'Assigned to me';

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

                <FormSection title="Leads by status" columns={1}>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(metrics.leads_by_status).map(
                            ([status, count]) => (
                                <div
                                    key={status}
                                    className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-1.5"
                                >
                                    <WorkspaceStatusBadge status={status} />
                                    <span className="text-sm font-semibold tabular-nums">
                                        {count}
                                    </span>
                                </div>
                            ),
                        )}
                    </div>
                </FormSection>

                <div className="grid gap-4 lg:grid-cols-2">
                    <FormSection
                        title="Recent leads"
                        description="Latest intake and status movement."
                        columns={1}
                    >
                        <ul className="space-y-3">
                            {metrics.recent_leads.length === 0 ? (
                                <li className="text-sm text-muted-foreground">
                                    No leads yet.
                                </li>
                            ) : (
                                metrics.recent_leads.map((lead) => (
                                    <li
                                        key={lead.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {lead.contact_name}
                                            </p>
                                            {lead.created_at ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {lead.created_at}
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <WorkspaceStatusBadge
                                                status={lead.status}
                                            />
                                            <AppIconAction
                                                icon={Eye}
                                                label="View lead"
                                                href={`/admin/crm/leads/${lead.id}`}
                                                variant="info"
                                            />
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>
                        <p className="pt-1">
                            <Link
                                href={urls.leads}
                                className="text-sm font-medium text-[#2D8CFF] underline-offset-4 hover:underline"
                            >
                                View all leads
                            </Link>
                        </p>
                    </FormSection>

                    <FormSection
                        title="Recent tickets"
                        description="Support queue snapshot."
                        columns={1}
                    >
                        <ul className="space-y-3">
                            {metrics.recent_tickets.length === 0 ? (
                                <li className="text-sm text-muted-foreground">
                                    No tickets yet.
                                </li>
                            ) : (
                                metrics.recent_tickets.map((ticket) => (
                                    <li
                                        key={ticket.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2"
                                    >
                                        <h3 className="min-w-0 flex-1 truncate text-sm font-medium">
                                            {ticket.subject}
                                        </h3>
                                        <div className="flex items-center gap-2">
                                            <WorkspaceStatusBadge
                                                status={ticket.status}
                                            />
                                            <AppIconAction
                                                icon={Headphones}
                                                label="View ticket"
                                                href={`/admin/crm/tickets/${ticket.id}`}
                                                variant="info"
                                            />
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Button asChild size="sm" variant="default">
                                <Link href={urls.create_ticket}>
                                    <Plus className="size-4" aria-hidden />
                                    New ticket
                                </Link>
                            </Button>
                        </div>
                    </FormSection>
                </div>
            </PageShell>
        </>
    );
}
