import { Head, Link, usePage } from '@inertiajs/react';
import { CompanySwitcher } from '@/components/company-switcher';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';

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
    drafts: Draft[];
    activation_note: string;
    links: { overview: string; catalogue: string };
};

export default function SubscriptionDrafts({
    company,
    drafts,
    activation_note,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    return (
        <>
            <Head title={`Drafts · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Draft contracts
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Draft contracts
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {activation_note}
                        </p>
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
                        {drafts.map((draft) => (
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
                                            {draft.common_renewal_date || '—'} ·
                                            ₹{draft.discounted_annual_total}
                                        </p>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        ID {draft.id}
                                    </p>
                                </div>
                                <ul className="mt-3 space-y-1 text-sm">
                                    {draft.items.map((item, i) => (
                                        <li
                                            key={`${item.name}-${i}`}
                                            className="flex justify-between gap-3"
                                        >
                                            <span>{item.name}</span>
                                            <span>
                                                ₹{item.annual_price_snapshot}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {draft.pricing_breakdown ? (
                                    <dl className="mt-3 grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                                        {Object.entries(draft.pricing_breakdown)
                                            .filter(
                                                ([, v]) =>
                                                    typeof v !== 'object',
                                            )
                                            .map(([k, v]) => (
                                                <div
                                                    key={k}
                                                    className="flex justify-between gap-2"
                                                >
                                                    <dt>{k}</dt>
                                                    <dd>{String(v)}</dd>
                                                </div>
                                            ))}
                                    </dl>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
