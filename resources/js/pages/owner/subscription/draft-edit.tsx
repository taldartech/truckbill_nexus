import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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

const STEPS = ['Modules', 'Plans', 'Add-ons', 'Tenure', 'Review'] as const;

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
    const [step, setStep] = useState(0);
    const [planIds, setPlanIds] = useState<string[]>(selection.plan_ids);
    const [addonIds, setAddonIds] = useState<string[]>(selection.addon_ids);
    const [tenure, setTenure] = useState(selection.tenure);
    const [renewal, setRenewal] = useState(selection.renewal_eligible);
    const [renewalDate, setRenewalDate] = useState(
        selection.common_renewal_date ?? '',
    );

    const modules = useMemo(() => {
        const names = [...new Set(plans.map((plan) => plan.module))];
        return names.sort();
    }, [plans]);

    const selectedModules = useMemo(() => {
        return [
            ...new Set(
                planIds
                    .map((id) => plans.find((plan) => plan.id === id)?.module)
                    .filter((module): module is string => Boolean(module)),
            ),
        ];
    }, [planIds, plans]);

    const toggleModule = (module: string) => {
        const modulePlans = plans.filter((plan) => plan.module === module);
        const hasAny = modulePlans.some((plan) => planIds.includes(plan.id));
        setPlanIds((current) => {
            const without = current.filter(
                (id) => plans.find((plan) => plan.id === id)?.module !== module,
            );
            if (hasAny) {
                return without;
            }
            const preferred =
                modulePlans.find((plan) =>
                    selection.plan_ids.includes(plan.id),
                ) ?? modulePlans[0];
            return preferred ? [...without, preferred.id] : without;
        });
    };

    const selectPlan = (id: string, module: string) => {
        setPlanIds((current) => {
            const withoutModule = current.filter(
                (planId) =>
                    plans.find((plan) => plan.id === planId)?.module !== module,
            );
            return [...withoutModule, id];
        });
    };

    const preview = () => {
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

    const canAdvance =
        step === 0
            ? selectedModules.length > 0
            : step === 1
              ? planIds.length > 0
              : step === 3
                ? Boolean(tenure && renewalDate)
                : true;

    return (
        <>
            <Head title={`Configure subscription · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="space-y-3">
                    <nav
                        aria-label="Breadcrumb"
                        className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Link href="/management" className="hover:underline">
                            Owner Panel
                        </Link>
                        <span aria-hidden>/</span>
                        <Link
                            href="/management/subscriptions"
                            className="hover:underline"
                        >
                            Subscriptions
                        </Link>
                        <span aria-hidden>/</span>
                        <Link href={links.overview} className="hover:underline">
                            {company.name}
                        </Link>
                        <span aria-hidden>/</span>
                        <span className="text-foreground">Configure</span>
                    </nav>
                    <div className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Configure subscription — {company.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {edit_note} Catalogue locked: {catalogue.name} (
                            {catalogue.code}).
                        </p>
                    </div>
                    <ol className="flex flex-wrap gap-2">
                        {STEPS.map((label, index) => (
                            <li key={label}>
                                <button
                                    type="button"
                                    onClick={() => setStep(index)}
                                    className={`rounded-full border px-3 py-1 text-xs font-medium ${
                                        index === step
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : index < step
                                              ? 'border-border bg-muted/40'
                                              : 'border-border text-muted-foreground'
                                    }`}
                                >
                                    {index + 1}. {label}
                                </button>
                            </li>
                        ))}
                    </ol>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-6">
                        {step === 0 ? (
                            <section className="space-y-3">
                                <h2 className="font-semibold">
                                    Step 1 — Select modules
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Choose one or more Core modules for this
                                    company subscription.
                                </p>
                                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {modules.map((module) => {
                                        const selected =
                                            selectedModules.includes(module);
                                        return (
                                            <li key={module}>
                                                <button
                                                    type="button"
                                                    disabled={!canManage}
                                                    onClick={() =>
                                                        toggleModule(module)
                                                    }
                                                    className={`w-full rounded-xl border p-4 text-left transition ${
                                                        selected
                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary/40'
                                                            : 'border-border hover:bg-muted/30'
                                                    }`}
                                                >
                                                    <p className="font-medium capitalize">
                                                        {module.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {
                                                            plans.filter(
                                                                (plan) =>
                                                                    plan.module ===
                                                                    module,
                                                            ).length
                                                        }{' '}
                                                        plan
                                                        {plans.filter(
                                                            (plan) =>
                                                                plan.module ===
                                                                module,
                                                        ).length === 1
                                                            ? ''
                                                            : 's'}{' '}
                                                        available
                                                    </p>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        ) : null}

                        {step === 1 ? (
                            <section className="space-y-4">
                                <h2 className="font-semibold">
                                    Step 2 — Select plans
                                </h2>
                                {selectedModules.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Select at least one module first.
                                    </p>
                                ) : (
                                    selectedModules.map((module) => (
                                        <div key={module} className="space-y-2">
                                            <h3 className="text-sm font-medium capitalize">
                                                {module.replaceAll('_', ' ')}
                                            </h3>
                                            <ul className="grid gap-3 sm:grid-cols-2">
                                                {plans
                                                    .filter(
                                                        (plan) =>
                                                            plan.module ===
                                                            module,
                                                    )
                                                    .map((plan) => {
                                                        const selected =
                                                            planIds.includes(
                                                                plan.id,
                                                            );
                                                        return (
                                                            <li key={plan.id}>
                                                                <button
                                                                    type="button"
                                                                    disabled={
                                                                        !canManage
                                                                    }
                                                                    onClick={() =>
                                                                        selectPlan(
                                                                            plan.id,
                                                                            plan.module,
                                                                        )
                                                                    }
                                                                    className={`w-full rounded-xl border p-4 text-left transition ${
                                                                        selected
                                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary/40'
                                                                            : 'border-border hover:bg-muted/30'
                                                                    }`}
                                                                >
                                                                    <p className="font-medium">
                                                                        {
                                                                            plan.name
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                                        ₹
                                                                        {
                                                                            plan.annual_price
                                                                        }
                                                                        /yr
                                                                    </p>
                                                                </button>
                                                            </li>
                                                        );
                                                    })}
                                            </ul>
                                        </div>
                                    ))
                                )}
                            </section>
                        ) : null}

                        {step === 2 ? (
                            <section className="space-y-3">
                                <h2 className="font-semibold">
                                    Step 3 — Configure add-ons
                                </h2>
                                {addons.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No add-ons in the active catalogue.
                                    </p>
                                ) : (
                                    <ul className="grid gap-3 sm:grid-cols-2">
                                        {addons.map((addon) => {
                                            const selected = addonIds.includes(
                                                addon.id,
                                            );
                                            return (
                                                <li key={addon.id}>
                                                    <button
                                                        type="button"
                                                        disabled={!canManage}
                                                        onClick={() =>
                                                            setAddonIds(
                                                                (current) =>
                                                                    current.includes(
                                                                        addon.id,
                                                                    )
                                                                        ? current.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  addon.id,
                                                                          )
                                                                        : [
                                                                              ...current,
                                                                              addon.id,
                                                                          ],
                                                            )
                                                        }
                                                        className={`w-full rounded-xl border p-4 text-left transition ${
                                                            selected
                                                                ? 'border-primary bg-primary/5 ring-1 ring-primary/40'
                                                                : 'border-border hover:bg-muted/30'
                                                        }`}
                                                    >
                                                        <p className="font-medium">
                                                            {addon.name}
                                                        </p>
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            ₹
                                                            {addon.annual_price}
                                                            /yr
                                                        </p>
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </section>
                        ) : null}

                        {step === 3 ? (
                            <section className="space-y-4">
                                <h2 className="font-semibold">
                                    Step 4 — Select tenure
                                </h2>
                                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {tenureOptions.map((option) => {
                                        const selected =
                                            tenure === option.value;
                                        return (
                                            <li key={option.value}>
                                                <button
                                                    type="button"
                                                    disabled={!canManage}
                                                    onClick={() =>
                                                        setTenure(option.value)
                                                    }
                                                    className={`w-full rounded-xl border p-4 text-left transition ${
                                                        selected
                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary/40'
                                                            : 'border-border hover:bg-muted/30'
                                                    }`}
                                                >
                                                    <p className="font-medium">
                                                        {option.label}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        −
                                                        {
                                                            option.discount_percent
                                                        }
                                                        % tenure discount
                                                    </p>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={renewal}
                                        onChange={(event) =>
                                            setRenewal(event.target.checked)
                                        }
                                        disabled={!canManage}
                                    />
                                    Renewal eligible (additional discount when
                                    applicable)
                                </label>
                                <div className="max-w-sm space-y-2">
                                    <Label htmlFor="common_renewal_date">
                                        Common renewal date
                                    </Label>
                                    <Input
                                        id="common_renewal_date"
                                        type="date"
                                        value={renewalDate}
                                        onChange={(event) =>
                                            setRenewalDate(event.target.value)
                                        }
                                        disabled={!canManage}
                                        required
                                    />
                                </div>
                            </section>
                        ) : null}

                        {step === 4 ? (
                            <section className="space-y-4">
                                <h2 className="font-semibold">
                                    Step 5 — Review subscription
                                </h2>
                                <dl className="grid gap-3 rounded-xl border border-border p-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Company
                                        </dt>
                                        <dd className="font-medium">
                                            {company.name}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Modules
                                        </dt>
                                        <dd className="font-medium capitalize">
                                            {selectedModules
                                                .map((module) =>
                                                    module.replaceAll('_', ' '),
                                                )
                                                .join(', ') || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Plans
                                        </dt>
                                        <dd className="font-medium">
                                            {planIds
                                                .map(
                                                    (id) =>
                                                        plans.find(
                                                            (plan) =>
                                                                plan.id === id,
                                                        )?.name,
                                                )
                                                .filter(Boolean)
                                                .join(', ') || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Add-ons
                                        </dt>
                                        <dd className="font-medium">
                                            {addonIds
                                                .map(
                                                    (id) =>
                                                        addons.find(
                                                            (addon) =>
                                                                addon.id === id,
                                                        )?.name,
                                                )
                                                .filter(Boolean)
                                                .join(', ') || 'None'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Tenure
                                        </dt>
                                        <dd className="font-medium">
                                            {tenureOptions.find(
                                                (option) =>
                                                    option.value === tenure,
                                            )?.label ?? tenure}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Common renewal
                                        </dt>
                                        <dd className="font-medium">
                                            {renewalDate || '—'}
                                        </dd>
                                    </div>
                                </dl>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={preview}
                                    disabled={
                                        !canManage || planIds.length === 0
                                    }
                                >
                                    Refresh server price preview
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Totals below are authoritative backend
                                    quotes. The frontend never invents pricing.
                                </p>
                            </section>
                        ) : null}

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={step === 0}
                                onClick={() =>
                                    setStep((current) => current - 1)
                                }
                            >
                                Back
                            </Button>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="ghost">
                                    <Link href={links.drafts}>
                                        Back to drafts
                                    </Link>
                                </Button>
                                {step < STEPS.length - 1 ? (
                                    <Button
                                        type="button"
                                        disabled={!canAdvance}
                                        onClick={() => {
                                            if (step === 3) {
                                                preview();
                                            }
                                            setStep((current) => current + 1);
                                        }}
                                    >
                                        Next
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    </div>

                    <aside className="h-fit space-y-4 xl:sticky xl:top-4">
                        <BreakdownPanel
                            title="Saved draft"
                            breakdown={before_breakdown}
                        />
                        <BreakdownPanel
                            title="Live preview"
                            breakdown={preview_breakdown}
                        />
                        {canManage && step === 4 ? (
                            <Form
                                action={links.update}
                                method="put"
                                className="space-y-3 rounded-xl border border-border p-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <h3 className="font-medium">
                                            Save draft
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
                                {canManage
                                    ? 'Complete the steps, then save the draft from Review.'
                                    : 'View-only access. Draft changes require full owner scope.'}
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
