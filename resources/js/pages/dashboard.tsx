import { Head, Link } from '@inertiajs/react';
import { LayoutDashboard } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import { dashboard } from '@/routes';

/**
 * Technical shell dashboard — Internal Team landing (Customer UI uses /workspace).
 */
export default function Dashboard() {
    return (
        <>
            <Head title="Workspace" />
            <PageShell
                title="Internal workspace"
                description="Use CRM and platform administration tools from the sidebar."
                actions={
                    <Button asChild variant="info">
                        <Link href="/admin/crm">
                            <LayoutDashboard className="size-4" />
                            CRM Dashboard
                        </Link>
                    </Button>
                }
            >
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <MetricCard
                        label="Portal"
                        value="Internal"
                        hint="Role-scoped navigation"
                        tone="blue"
                        icon={LayoutDashboard}
                    />
                </div>
                <ShellEmptyState
                    title="Platform administration"
                    description="Leads, support tickets, GST rates, TDS rules, and Super Admin subscription invoice approvals are available from the sidebar."
                />
            </PageShell>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Workspace',
            href: dashboard(),
        },
    ],
};
