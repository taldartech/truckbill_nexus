import { Form, Head, Link, usePage } from '@inertiajs/react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    lifecycle: {
        status: string;
        company_admin_active: boolean;
        branch_mutations_allowed: boolean;
        owner_billing_allowed: boolean;
        restriction_reason: string | null;
        matrix_authority: string;
        notes: string[];
        trial_ends_at: string | null;
        paid_through_date: string | null;
        grace_ends_on: string | null;
        cancellation_effective_on: string | null;
    };
    capacity: {
        included_branches: number;
        included_seats: number;
        purchased_branches: number;
        purchased_seats: number;
        used_branches: number;
        used_billable_seats: number;
        available_branch_slots: number;
        available_seat_slots: number;
        available_user_capacity: number;
        owner_is_billable_seat: boolean;
        used_vehicles: number;
        vehicle_capacity: number | null;
        utilized_vehicle_capacity: number;
    };
    storage: {
        allocated_bytes: number | null;
        used_bytes: number;
        remaining_bytes: number | null;
        counted_bytes: number;
        alert: boolean;
        block: boolean;
        percent_of_allocation: number | null;
        threshold_warning: boolean;
    };
    quotas: {
        id: string;
        quota_type: string;
        included_units: string;
        used_units: string;
        available_units: string;
        period_start: string | null;
        period_end: string | null;
        percent_used: number;
        alert: boolean;
        block: boolean;
    }[];
    scheduled_changes: {
        id: string;
        change_type: string;
        status: string;
        effective_on: string | null;
        remark: string | null;
    }[];
    cancellation: null | {
        subscription_id: string;
        pending: boolean;
        effective_on: string | null;
        remark: string | null;
    };
    credit_summary: {
        ledger_type: string;
        service_key?: string | null;
        balance: string;
        unit: string;
    }[];
    entitlement_note: string;
    payment_gate_note: string;
    links: {
        dashboard: string;
        catalogue: string;
        drafts: string;
        credits: string;
        payments: string;
        invoices: string;
        cancel: string;
        withdraw_cancel: string;
        checkout: string | null;
        renew: string;
    };
    payment_gateway?: string;
};

export default function SubscriptionOverview({
    company,
    ownerPanelScope: _ownerPanelScope,
    canManage,
    subscription,
    lifecycle,
    capacity,
    storage,
    quotas,
    scheduled_changes,
    cancellation,
    credit_summary,
    entitlement_note,
    payment_gate_note,
    links,
    catalogue,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: { remark?: string; checkout?: string };
    }>().props;

    const modules = (subscription?.items ?? [])
        .map((item) => item.module)
        .filter((module): module is string => Boolean(module));
    const uniqueModules = [...new Set(modules)];

    return (
        <>
            <Head title={`Subscription — ${company.name}`} />
            <OwnerPageHeader
                title={`Subscription — ${company.name}`}
                description="Subscription and capacity for this company."
                breadcrumbs={[
                    { label: 'Owner Panel', href: links.dashboard },
                    {
                        label: 'Subscriptions',
                        href: '/management/subscriptions',
                    },
                    { label: company.name },
                ]}
                actions={
                    <>
                        <WorkspaceStatusBadge status={company.status} />
                        <span className="rounded-md border border-border px-2 py-0.5 text-xs">
                            {lifecycle.status.replaceAll('_', ' ')}
                        </span>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/management/subscriptions">
                                Back to Subscriptions
                            </Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/management/companies">
                                View Company
                            </Link>
                        </Button>
                    </>
                }
            >
                {uniqueModules.length > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        Modules:{' '}
                        {uniqueModules
                            .map((module) => module.replaceAll('_', ' '))
                            .join(', ')}
                    </p>
                ) : null}
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.checkout ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.checkout}
                    </p>
                ) : null}
                <section className="space-y-2 rounded-xl border border-border bg-card p-3 shadow-xs">
                    <h2 className="text-sm font-semibold">Main actions</h2>
                    <div className="flex flex-wrap gap-2">
                        {canManage ? (
                            <Button asChild size="sm">
                                <Link href={links.drafts}>
                                    {subscription
                                        ? 'Add Module / Upgrade'
                                        : 'Subscribe'}
                                </Link>
                            </Button>
                        ) : null}
                        {canManage && links.checkout ? (
                            <Button asChild size="sm">
                                <Link href={links.checkout}>
                                    Pay / activate (Test Mode)
                                </Link>
                            </Button>
                        ) : null}
                        {canManage &&
                        subscription?.status === 'active' &&
                        links.renew ? (
                            <Form
                                action={links.renew}
                                method="post"
                                className="inline"
                            >
                                <input
                                    type="hidden"
                                    name="subscription_id"
                                    value={subscription.id}
                                />
                                <Button type="submit" size="sm">
                                    Renew
                                </Button>
                            </Form>
                        ) : null}
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.catalogue}>
                                Catalogue & pricing
                            </Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.invoices}>View Invoices</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.payments}>Payment History</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.credits}>Credits & quotas</Link>
                        </Button>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={links.drafts}>Draft contracts</Link>
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {entitlement_note}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {payment_gate_note}
                    </p>
                    {catalogue ? (
                        <p className="text-xs text-muted-foreground">
                            Active catalogue: {catalogue.name} ({catalogue.code}
                            )
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No active catalogue configured.
                        </p>
                    )}
                </section>
                {lifecycle.restriction_reason ? (
                    <p
                        role="status"
                        className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
                    >
                        {lifecycle.restriction_reason}
                    </p>
                ) : null}
                <section className="grid gap-3 rounded-xl border border-border bg-card p-3 shadow-xs md:grid-cols-3">
                    <div>
                        <h2 className="text-sm font-semibold">
                            Current subscription
                        </h2>
                        <dl className="mt-2 space-y-1.5 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd>{lifecycle.status}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Trial ends
                                </dt>
                                <dd>{lifecycle.trial_ends_at || '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Paid through
                                </dt>
                                <dd>{lifecycle.paid_through_date || '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Grace ends
                                </dt>
                                <dd>{lifecycle.grace_ends_on || '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Branch mutations
                                </dt>
                                <dd>
                                    {lifecycle.branch_mutations_allowed
                                        ? 'Allowed'
                                        : 'Blocked'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h2 className="text-sm font-semibold">Capacity</h2>
                        <dl className="mt-2 space-y-1.5 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Branches (used / included + purchased)
                                </dt>
                                <dd>
                                    {capacity.used_branches} /{' '}
                                    {capacity.included_branches}+
                                    {capacity.purchased_branches}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Users (used / available)
                                </dt>
                                <dd>
                                    {capacity.used_billable_seats} /{' '}
                                    {capacity.available_user_capacity} free
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Vehicles
                                </dt>
                                <dd>
                                    {capacity.utilized_vehicle_capacity}
                                    {capacity.vehicle_capacity != null
                                        ? ` / ${capacity.vehicle_capacity}`
                                        : ' used'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Owner billable
                                </dt>
                                <dd>
                                    {capacity.owner_is_billable_seat
                                        ? 'Yes'
                                        : 'No'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h2 className="text-sm font-semibold">Storage</h2>
                        <dl className="mt-2 space-y-1.5 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Used / allocated
                                </dt>
                                <dd>
                                    {storage.used_bytes}
                                    {storage.allocated_bytes != null
                                        ? ` / ${storage.allocated_bytes}`
                                        : ''}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Remaining
                                </dt>
                                <dd>
                                    {storage.remaining_bytes != null
                                        ? storage.remaining_bytes
                                        : '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Utilization
                                </dt>
                                <dd>
                                    {storage.percent_of_allocation != null
                                        ? `${storage.percent_of_allocation}%`
                                        : '—'}
                                    {storage.threshold_warning
                                        ? ' · warning'
                                        : ''}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>
                <section className="space-y-2 rounded-xl border border-border bg-card p-3 shadow-xs">
                    <h2 className="text-sm font-semibold">
                        Quotas (Broker / Partload)
                    </h2>
                    {quotas.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No quota counters allocated yet.
                        </p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {quotas.map((quota) => (
                                <li
                                    key={quota.id}
                                    className="rounded-lg border border-border/70 p-3 text-sm"
                                >
                                    <p className="font-medium">
                                        {quota.quota_type}
                                    </p>
                                    <p className="text-muted-foreground">
                                        Included {quota.included_units} · Used{' '}
                                        {quota.used_units} · Available{' '}
                                        {quota.available_units}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Period {quota.period_start || '—'} →{' '}
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
                {scheduled_changes.length > 0 ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Scheduled changes</h2>
                        <ul className="space-y-2 text-sm">
                            {scheduled_changes.map((change) => (
                                <li
                                    key={change.id}
                                    className="flex flex-wrap justify-between gap-2 border-b border-border/60 py-2"
                                >
                                    <span>
                                        {change.change_type} ({change.status})
                                    </span>
                                    <span className="text-muted-foreground">
                                        Effective {change.effective_on || '—'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
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
                {canManage && cancellation ? (
                    <section className="space-y-3 rounded-xl border border-border p-4">
                        <h2 className="font-semibold">Cancellation</h2>
                        {cancellation.pending ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Pending cancellation effective{' '}
                                    {cancellation.effective_on || '—'}.
                                    {cancellation.remark
                                        ? ` Remark: ${cancellation.remark}`
                                        : ''}
                                </p>
                                <Form
                                    action={links.withdraw_cancel}
                                    method="post"
                                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                                >
                                    <input
                                        type="hidden"
                                        name="subscription_id"
                                        value={cancellation.subscription_id}
                                    />
                                    <div className="flex flex-1 flex-col gap-1.5">
                                        <Label
                                            htmlFor="withdraw_cancel_remark"
                                            required
                                        >
                                            Withdrawal remark
                                        </Label>
                                        <Input
                                            id="withdraw_cancel_remark"
                                            name="remark"
                                            required
                                            placeholder="Keep subscription"
                                        />
                                    </div>
                                    <Button type="submit" variant="outline">
                                        Withdraw cancellation
                                    </Button>
                                </Form>
                            </>
                        ) : subscription?.status === 'active' ? (
                            <Form
                                action={links.cancel}
                                method="post"
                                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            >
                                <input
                                    type="hidden"
                                    name="subscription_id"
                                    value={cancellation.subscription_id}
                                />
                                <div className="flex flex-1 flex-col gap-1.5">
                                    <Label htmlFor="cancel_remark" required>
                                        Cancellation remark
                                    </Label>
                                    <Input
                                        id="cancel_remark"
                                        name="remark"
                                        required
                                        placeholder="Cancel at renewal"
                                    />
                                </div>
                                <Button type="submit" variant="outline">
                                    Schedule cancellation
                                </Button>
                            </Form>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Cancellation is available for active
                                subscriptions only.
                            </p>
                        )}
                        {errors?.remark ? (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {errors.remark}
                            </p>
                        ) : null}
                    </section>
                ) : null}
                <section className="space-y-3">
                    <h2 className="font-semibold">
                        Credit balances (non-cash)
                    </h2>
                    {credit_summary.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No credit ledgers yet.
                        </p>
                    ) : (
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {credit_summary.map((ledger) => (
                                <li
                                    key={`${ledger.ledger_type}-${ledger.unit}`}
                                    className="rounded-lg border border-border bg-card p-2.5 shadow-xs"
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
            </OwnerPageHeader>
        </>
    );
}
