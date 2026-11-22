import { Form, Head, Link, usePage } from '@inertiajs/react';
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
        used_branches: number;
        used_billable_seats: number;
        available_branch_slots: number;
        available_seat_slots: number;
        owner_is_billable_seat: boolean;
    };
    storage: {
        counted_bytes: number;
        alert: boolean;
        block: boolean;
        percent_of_allocation: number | null;
    };
    cancellation: null | {
        subscription_id: string;
        pending: boolean;
        effective_on: string | null;
        remark: string | null;
    };
    credit_summary: { ledger_type: string; balance: string; unit: string }[];
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
    ownerPanelScope,
    canManage,
    subscription,
    lifecycle,
    capacity,
    storage,
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
                            <span className="rounded-md border border-border px-2 py-0.5 text-xs">
                                Lifecycle: {lifecycle.status}
                            </span>
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
                {errors?.checkout ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.checkout}
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
                    <Button asChild variant="outline">
                        <Link href={links.payments}>Payment history</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={links.invoices}>Invoices</Link>
                    </Button>
                    {canManage && links.checkout ? (
                        <Button asChild>
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
                            <Button type="submit" variant="outline">
                                Renew (Test Mode)
                            </Button>
                        </Form>
                    ) : null}
                    <Button asChild variant="ghost">
                        <Link href={links.dashboard}>Owner dashboard</Link>
                    </Button>
                </div>
                <p className="text-sm text-muted-foreground">
                    {entitlement_note}
                </p>
                <p className="text-sm text-muted-foreground">
                    {payment_gate_note}
                </p>
                {lifecycle.restriction_reason ? (
                    <p
                        role="status"
                        className="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
                    >
                        {lifecycle.restriction_reason}
                    </p>
                ) : null}
                {catalogue ? (
                    <p className="text-sm">
                        Active catalogue: {catalogue.name} ({catalogue.code})
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No active catalogue configured.
                    </p>
                )}
                <section className="grid gap-4 rounded-xl border border-border p-4 md:grid-cols-3">
                    <div>
                        <h2 className="font-semibold">Lifecycle</h2>
                        <dl className="mt-3 space-y-2 text-sm">
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
                        <h2 className="font-semibold">Capacity</h2>
                        <dl className="mt-3 space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Branches
                                </dt>
                                <dd>
                                    {capacity.used_branches} /{' '}
                                    {capacity.included_branches}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Billable seats
                                </dt>
                                <dd>
                                    {capacity.used_billable_seats} /{' '}
                                    {capacity.included_seats}
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
                        <h2 className="font-semibold">Storage</h2>
                        <dl className="mt-3 space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Counted bytes
                                </dt>
                                <dd>{storage.counted_bytes}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Alert</dt>
                                <dd>{storage.alert ? 'Yes' : 'No'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Block</dt>
                                <dd>{storage.block ? 'Yes' : 'No'}</dd>
                            </div>
                        </dl>
                    </div>
                </section>
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
                                    <label className="flex flex-1 flex-col gap-1 text-sm">
                                        Withdrawal remark
                                        <input
                                            name="remark"
                                            required
                                            className="rounded-md border border-border bg-background px-3 py-2"
                                            placeholder="Keep subscription"
                                        />
                                    </label>
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
                                <label className="flex flex-1 flex-col gap-1 text-sm">
                                    Cancellation remark
                                    <input
                                        name="remark"
                                        required
                                        className="rounded-md border border-border bg-background px-3 py-2"
                                        placeholder="Cancel at renewal"
                                    />
                                </label>
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
