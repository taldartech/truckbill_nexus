import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CompanySwitcher } from '@/components/company-switcher';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { pricingBreakdownRows } from '@/lib/pricing-breakdown-labels';

type Plan = {
    id: string;
    module: string;
    code: string;
    name: string;
    annual_price: string;
    display_list_price: string;
};

type Props = {
    company: { id: string; name: string; status: string };
    canManage: boolean;
    catalogue: { id: string; code: string; name: string };
    draft: {
        id: string;
        status: string;
        catalogue_version_id: string;
        tenure: string;
        common_renewal_date: string | null;
        renewal_eligible: boolean;
        list_annual_total: string;
        discounted_annual_total: string;
        updated_at: string | null;
        snapshot_count: number;
        pricing_breakdown: Record<string, unknown> | null;
        items: {
            plan_id: string | null;
            addon_id: string | null;
            name: string;
            item_type: string;
        }[];
        snapshots?: { id: string; reason: string; created_at: string | null }[];
    };
    plans: Plan[];
    addons: { id: string; code: string; name: string; annual_price: string }[];
    tenureOptions: { value: string; label: string; discount_percent: string }[];
    selection: {
        plan_ids: string[];
        addon_ids: string[];
        tenure: string;
        renewal_eligible: boolean;
        common_renewal_date: string | null;
    };
    before_breakdown: Record<string, unknown> | null;
    preview_breakdown: Record<string, unknown> | null;
    edit_note: string;
    links: {
        drafts: string;
        preview: string;
        update: string;
        overview: string;
    };
};

function BreakdownPanel({
    title,
    breakdown,
}: {
    title: string;
    breakdown: Record<string, unknown> | null;
}) {
    const rows = pricingBreakdownRows(breakdown);
    return (
        <section className="space-y-2 rounded-xl border border-border p-4">
            <h2 className="font-semibold">{title}</h2>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">No pricing yet.</p>
            ) : (
                <dl className="space-y-2 text-sm">
                    {rows.map((row) => (
                        <div
                            key={row.key}
                            className="flex justify-between gap-3"
                        >
                            <dt className="text-muted-foreground">
                                {row.label}
                            </dt>
                            <dd className="font-medium">{row.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

export default function DraftEdit({
    company,
    canManage,
    catalogue,
    draft,
    plans,
    addons,
    tenureOptions,
    selection,
    before_breakdown,
    preview_breakdown,
    edit_note,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [planIds, setPlanIds] = useState<string[]>(selection.plan_ids);
    const [addonIds, setAddonIds] = useState<string[]>(selection.addon_ids);
    const [tenure, setTenure] = useState(selection.tenure);
    const [renewal, setRenewal] = useState(selection.renewal_eligible);
    const [renewalDate, setRenewalDate] = useState(
        selection.common_renewal_date ?? '',
    );

    const togglePlan = (id: string, module: string) => {
        setPlanIds((current) => {
            const withoutModule = current.filter(
                (planId) =>
                    plans.find((p) => p.id === planId)?.module !== module,
            );
            return current.includes(id)
                ? withoutModule
                : [...withoutModule, id];
        });
    };

    const preview = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.preview,
            {
                plan_ids: planIds,
                addon_ids: addonIds,
                tenure,
                renewal_eligible: renewal ? 1 : 0,
                common_renewal_date: renewalDate || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`Edit draft · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Edit draft
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Edit draft contract
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {edit_note}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Catalogue locked: {catalogue.name} ({catalogue.code}
                            )
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
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={links.drafts}>Back to drafts</Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={links.overview}>Overview</Link>
                    </Button>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <form onSubmit={preview} className="space-y-6">
                        <section className="space-y-3">
                            <h2 className="font-semibold">Core modules</h2>
                            <ul className="space-y-2">
                                {plans.map((plan) => (
                                    <li
                                        key={plan.id}
                                        className="flex items-start gap-3 rounded-xl border border-border p-3"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={planIds.includes(plan.id)}
                                            onChange={() =>
                                                togglePlan(plan.id, plan.module)
                                            }
                                            className="mt-1"
                                            disabled={!canManage}
                                            aria-label={`Select ${plan.name}`}
                                        />
                                        <div className="flex-1">
                                            <p className="font-medium">
                                                {plan.name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {plan.module} · ₹
                                                {plan.annual_price}/yr
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                        <section className="space-y-3">
                            <h2 className="font-semibold">Add-ons</h2>
                            <ul className="space-y-2">
                                {addons.map((addon) => (
                                    <li
                                        key={addon.id}
                                        className="flex items-start gap-3 rounded-xl border border-border p-3"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={addonIds.includes(
                                                addon.id,
                                            )}
                                            onChange={() =>
                                                setAddonIds((c) =>
                                                    c.includes(addon.id)
                                                        ? c.filter(
                                                              (x) =>
                                                                  x !==
                                                                  addon.id,
                                                          )
                                                        : [...c, addon.id],
                                                )
                                            }
                                            className="mt-1"
                                            disabled={!canManage}
                                            aria-label={`Select ${addon.name}`}
                                        />
                                        <div>
                                            <p className="font-medium">
                                                {addon.name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                ₹{addon.annual_price}/yr
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="tenure">Tenure</Label>
                                <select
                                    id="tenure"
                                    value={tenure}
                                    onChange={(e) => setTenure(e.target.value)}
                                    disabled={!canManage}
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {tenureOptions.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label} (−{o.discount_percent}%)
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <label className="flex items-center gap-2 self-end text-sm">
                                <input
                                    type="checkbox"
                                    checked={renewal}
                                    onChange={(e) =>
                                        setRenewal(e.target.checked)
                                    }
                                    disabled={!canManage}
                                />{' '}
                                Renewal eligible (5%)
                            </label>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="common_renewal_date">
                                    Common renewal date
                                </Label>
                                <Input
                                    id="common_renewal_date"
                                    type="date"
                                    value={renewalDate}
                                    onChange={(e) =>
                                        setRenewalDate(e.target.value)
                                    }
                                    disabled={!canManage}
                                    required
                                />
                            </div>
                        </div>
                        <Button type="submit" variant="secondary">
                            Recalculate preview
                        </Button>
                    </form>

                    <aside className="h-fit space-y-4 xl:sticky xl:top-4">
                        <BreakdownPanel
                            title="Saved draft (before)"
                            breakdown={before_breakdown}
                        />
                        <BreakdownPanel
                            title="Preview (after recalculation)"
                            breakdown={preview_breakdown}
                        />
                        {canManage ? (
                            <Form
                                action={links.update}
                                method="put"
                                className="space-y-3 rounded-xl border border-border p-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <h3 className="font-medium">
                                            Save updated draft
                                        </h3>
                                        <input
                                            type="hidden"
                                            name="expected_snapshot_count"
                                            value={draft.snapshot_count}
                                        />
                                        <input
                                            type="hidden"
                                            name="tenure"
                                            value={tenure}
                                        />
                                        {renewal ? (
                                            <input
                                                type="hidden"
                                                name="renewal_eligible"
                                                value="1"
                                            />
                                        ) : null}
                                        {planIds.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="plan_ids[]"
                                                value={id}
                                            />
                                        ))}
                                        {addonIds.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="addon_ids[]"
                                                value={id}
                                            />
                                        ))}
                                        <input
                                            type="hidden"
                                            name="common_renewal_date"
                                            value={renewalDate}
                                        />
                                        <div className="space-y-2">
                                            <Label htmlFor="remark">
                                                Remark
                                            </Label>
                                            <Input
                                                id="remark"
                                                name="remark"
                                                defaultValue="Update subscription draft contract"
                                                required
                                            />
                                            <InputError
                                                message={errors.remark}
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                errors.expected_snapshot_count ||
                                                errors.plan_ids
                                            }
                                        />
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                planIds.length === 0
                                            }
                                        >
                                            {processing
                                                ? 'Saving…'
                                                : 'Save draft (no activation)'}
                                        </Button>
                                        <p className="text-xs text-muted-foreground">
                                            Current draft ₹
                                            {draft.discounted_annual_total} ·
                                            status {draft.status}
                                        </p>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                View-only access. Draft changes require full
                                owner scope.
                            </p>
                        )}
                        {draft.snapshots && draft.snapshots.length > 0 ? (
                            <section className="space-y-2 rounded-xl border border-border p-4">
                                <h2 className="font-semibold">
                                    Immutable snapshots
                                </h2>
                                <ul className="space-y-1 text-sm text-muted-foreground">
                                    {draft.snapshots.map((snapshot) => (
                                        <li key={snapshot.id}>
                                            {snapshot.reason} ·{' '}
                                            {snapshot.created_at ?? '—'}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ) : null}
                    </aside>
                </div>
            </div>
        </>
    );
}
