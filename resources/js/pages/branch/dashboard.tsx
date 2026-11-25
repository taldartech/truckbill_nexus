import { Head, Link } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Fuel,
    LayoutGrid,
    Settings,
    Truck,
    Wallet,
} from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/ui/metric-card';
import type { WorkspaceBranch } from '@/types/workspace';

type ModuleShortcut = {
    key: string;
    label: string;
    href: string;
    description?: string;
};

type OwnFleetOverview = {
    entitled: boolean;
    can_view_trips: boolean;
    can_create_trips: boolean;
    metrics: {
        own_vehicles: number;
        available_vehicles: number;
        vehicles_on_trip: number;
        trips_pending: number;
        trips_in_progress: number;
        trips_completed: number;
        bilty_awaiting_trip: number;
        expenses_recorded: number;
    };
    links: {
        bilty_register: string | null;
        create_bilty: string | null;
        awaiting_trip: string | null;
        active_trips: string | null;
    };
};

type BrokerOverview = {
    entitled: boolean;
    can_view_hires: boolean;
    metrics: {
        bilty_awaiting_hire: number;
        open_hires: number;
    };
    links: {
        bilty_register: string | null;
        lorry_hires: string | null;
        vouchers: string | null;
    };
};

type BranchDashboardProps = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    isOwner: boolean;
    modules: ModuleShortcut[];
    ownFleet?: OwnFleetOverview | null;
    broker?: BrokerOverview | null;
    siblingBranches: WorkspaceBranch[];
    links: {
        dashboard: string;
        settings: string;
        owner_panel: string | null;
        select_branch: string | null;
        bilty_register?: string;
        create_bilty?: string;
    };
};

export default function BranchDashboard({
    branch,
    modules,
    ownFleet = null,
    broker = null,
    links,
}: BranchDashboardProps) {
    return (
        <>
            <Head title="Operations" />
            <PageShell
                title="Dashboard"
                description={`${branch.name} — OwnFleet and Broker operations in one workspace.`}
                actions={
                    <>
                        {links.create_bilty ? (
                            <AppIconAction
                                icon={Truck}
                                label="Create Bilty"
                                href={links.create_bilty}
                                variant="default"
                                size="default"
                                className="gap-2 px-3"
                            >
                                <span className="text-sm font-medium">
                                    Create Bilty
                                </span>
                            </AppIconAction>
                        ) : null}
                        <AppIconAction
                            icon={Settings}
                            label="Branch settings"
                            href={links.settings}
                            variant="outline"
                            size="default"
                            className="gap-2 px-3"
                        >
                            <span className="text-sm font-medium">
                                Settings
                            </span>
                        </AppIconAction>
                        {links.select_branch ? (
                            <Button asChild size="sm" variant="secondary">
                                <Link href={links.select_branch}>
                                    <ArrowRightLeft className="size-4" />
                                    Switch branch
                                </Link>
                            </Button>
                        ) : null}
                    </>
                }
            >
                {ownFleet?.entitled ? (
                    <section className="mb-6 space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold text-slate-800">
                                OwnFleet operations
                            </h2>
                            <div className="flex flex-wrap gap-2">
                                {ownFleet.links.awaiting_trip ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link
                                            href={ownFleet.links.awaiting_trip}
                                        >
                                            Start Trip / Expenses
                                        </Link>
                                    </Button>
                                ) : null}
                                {ownFleet.links.active_trips ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link
                                            href={ownFleet.links.active_trips}
                                        >
                                            <Wallet className="size-3.5" />
                                            Active Trip Ops
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                label="Own vehicles"
                                value={ownFleet.metrics.own_vehicles}
                                hint={`${ownFleet.metrics.available_vehicles} available · ${ownFleet.metrics.vehicles_on_trip} on trip`}
                                icon={Truck}
                                tone="green"
                            />
                            <MetricCard
                                label="Trips pending"
                                value={ownFleet.metrics.trips_pending}
                                hint={`${ownFleet.metrics.trips_in_progress} in progress · ${ownFleet.metrics.trips_completed} completed`}
                                icon={LayoutGrid}
                                tone="blue"
                            />
                            <MetricCard
                                label="Bilty awaiting Trip"
                                value={ownFleet.metrics.bilty_awaiting_trip}
                                hint="Own vehicle Bilties without Trip yet"
                                icon={Truck}
                                tone="amber"
                            />
                            <MetricCard
                                label="Trip expenses"
                                value={ownFleet.metrics.expenses_recorded}
                                hint="Recorded diesel and expense lines"
                                icon={Fuel}
                                tone="amber"
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Workflow: Create Own Vehicle Bilty → Start Trip /
                            Manage Expenses → Dispatch → Diesel &amp; Expenses →
                            Complete → Invoice → Receipt. Open any Own Vehicle
                            row in the Bilty Register for Trip Operations.
                        </p>
                    </section>
                ) : null}

                {broker?.entitled ? (
                    <section className="mb-6 space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold text-slate-800">
                                Broker operations
                            </h2>
                            <div className="flex flex-wrap gap-2">
                                {broker.links.lorry_hires ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={broker.links.lorry_hires}>
                                            Lorry Hires
                                        </Link>
                                    </Button>
                                ) : null}
                                {broker.links.vouchers ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={broker.links.vouchers}>
                                            Voucher Payments
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                label="Bilty awaiting Hire"
                                value={broker.metrics.bilty_awaiting_hire}
                                hint="Market vehicle Bilties without Lorry Hire"
                                icon={Truck}
                                tone="blue"
                            />
                            <MetricCard
                                label="Open Lorry Hires"
                                value={broker.metrics.open_hires}
                                hint="Pending or active transporter obligations"
                                icon={Wallet}
                                tone="green"
                            />
                        </div>
                    </section>
                ) : null}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold text-slate-800">
                        Shortcuts
                    </h2>
                    {modules.length > 0 ? (
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {modules.map((module) => (
                                <li key={module.key}>
                                    <Link
                                        href={module.href}
                                        className="flex h-full flex-col gap-2 rounded-xl border border-border bg-card px-4 py-4 text-sm font-medium shadow-xs transition-colors hover:border-primary/40 hover:bg-muted/30"
                                    >
                                        <span className="text-foreground">
                                            {module.label}
                                        </span>
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {module.description ??
                                                'Open module →'}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <ShellEmptyState
                            title="Ready for operations"
                            description="Use Bilty Register from the sidebar. Select an Own Vehicle for OwnFleet or a Market Vehicle for Broker."
                        />
                    )}
                </section>
            </PageShell>
        </>
    );
}
