import { Head, Link, usePage } from '@inertiajs/react';
import { CompanyOwnerPageShell } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { pricingBreakdownRows } from '@/lib/pricing-breakdown-labels';

type Draft = {
    id: string;
    status: string;
    tenure: string;
    common_renewal_date: string | null;
    list_annual_total: string;
    discounted_annual_total: string;
    pricing_breakdown: Record<string, unknown> | null;
    items: {
        name: string;
        module: string | null;
        annual_price_snapshot: string;
    }[];
    snapshots?: { id: string; reason: string; created_at: string | null }[];
};

type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    drafts: Draft[];
    activation_note: string;
    links: { overview: string; catalogue: string };
    editLinks: Record<string, string>;
};

export default function SubscriptionDrafts({
    company,
    ownerPanelScope,
    canManage,
    drafts,
    activation_note,
    links,
    editLinks,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    return (
        <>
            <Head title={`Drafts · ${company.name}`} />
            <CompanyOwnerPageShell
                company={company}
                ownerPanelScope={ownerPanelScope}
                section="Draft contracts"
            >
                <p className="text-sm text-muted-foreground">
                    {activation_note}
                </p>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex gap-2">
                    <Button asChild variant="outline">
                        <Link href={links.overview}>Overview</Link>
                    </Button>
                    <Button asChild>
                        <Link href={links.catalogue}>
                            Create from catalogue
                        </Link>
                    </Button>
                </div>
                {drafts.length === 0 ? (
                    <ShellEmptyState
                        title="No drafts"
                        description="Calculate pricing and create a draft from the catalogue."
                    />
                ) : (
                    <ul className="space-y-4">
                        {drafts.map((draft) => {
                            const rows = pricingBreakdownRows(
                                draft.pricing_breakdown,
                            );
                            return (
                                <li
                                    key={draft.id}
                                    className="rounded-xl border border-border p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">
                                                {draft.status} · {draft.tenure}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                Renewal{' '}
                                                {draft.common_renewal_date ||
                                                    '—'}{' '}
                                                · ₹
                                                {draft.discounted_annual_total}
                                            </p>
                                        </div>
                                        <div className="flex flex-col items-end gap-2">
                                            <p className="text-xs text-muted-foreground">
                                                ID {draft.id}
                                            </p>
                                            {canManage &&
                                            editLinks[draft.id] ? (
                                                <Button asChild size="sm">
                                                    <Link
                                                        href={
                                                            editLinks[draft.id]
                                                        }
                                                    >
                                                        Edit draft
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                    <ul className="mt-3 space-y-1 text-sm">
                                        {draft.items.map((item, i) => (
                                            <li
                                                key={`${item.name}-${i}`}
                                                className="flex justify-between gap-3"
                                            >
                                                <span>{item.name}</span>
                                                <span>
                                                    ₹
                                                    {item.annual_price_snapshot}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    {rows.length > 0 ? (
                                        <dl className="mt-3 grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                                            {rows.map((row) => (
                                                <div
                                                    key={row.key}
                                                    className="flex justify-between gap-2"
                                                >
                                                    <dt>{row.label}</dt>
                                                    <dd>{row.value}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                    ) : null}
                                    {draft.snapshots &&
                                    draft.snapshots.length > 0 ? (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {draft.snapshots.length} immutable
                                            snapshot
                                            {draft.snapshots.length === 1
                                                ? ''
                                                : 's'}
                                        </p>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CompanyOwnerPageShell>
        </>
    );
}
