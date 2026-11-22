import { Head, Link, usePage } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    catalogue: { id: string; code: string; name: string } | null;
    subscription: null | {
        id: string;
        status: string;
        tenure: string;
        common_renewal_date: string | null;
        list_annual_total: string;
        discounted_annual_total: string;
        items: {
            module: string | null;
            name: string;
            capacity_units_snapshot: number | null;
        }[];
        pricing_breakdown: Record<string, unknown> | null;
    };
    credit_summary: { ledger_type: string; balance: string; unit: string }[];
    entitlement_note: string;
    links: {
        dashboard: string;
        catalogue: string;
        drafts: string;
        credits: string;
    };
};

export default function SubscriptionOverview({
    company,
    ownerPanelScope,
    subscription,
    credit_summary,
    entitlement_note,
    links,
    catalogue,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    return (
        <>
            <Head title={`Subscription · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Subscription
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <WorkspaceStatusBadge status={company.status} />
                            <span>Scope: {ownerPanelScope}</span>
                        </div>
                    </div>
                    <div className="w-full max-w-sm">
                        <CompanySwitcher />
                    </div>
                </div>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={links.catalogue}>Catalogue & pricing</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={links.drafts}>Draft contracts</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={links.credits}>Credits & quotas</Link>
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={links.dashboard}>Owner dashboard</Link>
                    </Button>
                </div>
                <p className="text-sm text-muted-foreground">
                    {entitlement_note}
                </p>
                {catalogue ? (
                    <p className="text-sm">
                        Active catalogue: {catalogue.name} ({catalogue.code})
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No active catalogue configured.
                    </p>
                )}
                {subscription ? (
                    <section className="grid gap-4 rounded-xl border border-border p-4 lg:grid-cols-2">
                        <div>
                            <h2 className="font-semibold">
                                Current contract snapshot
                            </h2>
                            <dl className="mt-3 space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Status
                                    </dt>
                                    <dd>{subscription.status}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Tenure
                                    </dt>
                                    <dd>{subscription.tenure}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Common renewal
                                    </dt>
                                    <dd>
                                        {subscription.common_renewal_date ||
                                            '—'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        List annual
                                    </dt>
                                    <dd>₹ {subscription.list_annual_total}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">
                                        Discounted annual
                                    </dt>
                                    <dd>
                                        ₹ {subscription.discounted_annual_total}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div>
                            <h2 className="font-semibold">Modules / items</h2>
                            <ul className="mt-3 space-y-2 text-sm">
                                {subscription.items.map((item, index) => (
                                    <li
                                        key={`${item.name}-${index}`}
                                        className="flex justify-between gap-3 border-b border-border/60 py-2"
                                    >
                                        <span>
                                            {item.name}
                                            {item.module
                                                ? ` (${item.module})`
                                                : ''}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {item.capacity_units_snapshot ??
                                                '—'}{' '}
                                            capacity
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                ) : (
                    <ShellEmptyState
                        title="No subscription contract yet"
                        description="Use the catalogue to calculate pricing and create a draft. Drafts do not activate billing."
                        action={
                            <Button asChild>
                                <Link href={links.catalogue}>
                                    Open catalogue
                                </Link>
                            </Button>
                        }
                    />
                )}
                <section className="space-y-3">
                    <h2 className="font-semibold">
                        Credit balances (non-cash)
                    </h2>
                    {credit_summary.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No credit ledgers yet.
                        </p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {credit_summary.map((ledger) => (
                                <li
                                    key={`${ledger.ledger_type}-${ledger.unit}`}
                                    className="rounded-xl border border-border p-3"
                                >
                                    <p className="text-sm text-muted-foreground">
                                        {ledger.unit}
                                    </p>
                                    <p className="text-xl font-semibold">
                                        {ledger.balance}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
