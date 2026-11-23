import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { pricingBreakdownRows } from '@/lib/pricing-breakdown-labels';

type Plan = {
    id: string;
    module: string;
    code: string;
    name: string;
    annual_price: string;
    display_list_price: string;
    capacity_units: number | null;
};
type Props = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    canManage: boolean;
    catalogue: { id: string; code: string; name: string } | null;
    plans: Plan[];
    addons: { id: string; code: string; name: string; annual_price: string }[];
    tenureOptions: { value: string; label: string; discount_percent: string }[];
    selection: {
        plan_ids: string[];
        tenure: string;
        renewal_eligible: boolean;
        coupon_percent: string | null;
        coupon_fixed: string | null;
    };
    breakdown: null | Record<string, unknown>;
    gst_note: string;
    links: {
        overview: string;
        calculate: string;
        create_draft: string;
        drafts: string;
    };
};

export default function SubscriptionCatalogue({
    company,
    canManage,
    catalogue,
    plans,
    addons,
    tenureOptions,
    selection,
    breakdown,
    gst_note,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [planIds, setPlanIds] = useState<string[]>(selection.plan_ids);
    const [tenure, setTenure] = useState(selection.tenure);
    const [renewal, setRenewal] = useState(selection.renewal_eligible);
    const [couponPercent, setCouponPercent] = useState(
        selection.coupon_percent ?? '',
    );
    const [couponFixed, setCouponFixed] = useState(
        selection.coupon_fixed ?? '',
    );
    const [addonIds, setAddonIds] = useState<string[]>([]);

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

    const calculate = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.calculate,
            {
                plan_ids: planIds,
                tenure,
                renewal_eligible: renewal ? 1 : 0,
                coupon_percent: couponPercent || undefined,
                coupon_fixed: couponFixed || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`Catalogue · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Catalogue & pricing
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Pricing calculator
                        </h1>
                        <WorkspaceStatusBadge status={company.status} />
                        <p className="text-sm text-muted-foreground">
                            Server-calculated totals only. {gst_note}
                        </p>
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
                <Button asChild variant="outline" className="self-start">
                    <Link href={links.overview}>Back to overview</Link>
                </Button>
                {!catalogue ? (
                    <p className="text-sm text-muted-foreground">
                        No active catalogue.
                    </p>
                ) : (
                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <form onSubmit={calculate} className="space-y-6">
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
                                                checked={planIds.includes(
                                                    plan.id,
                                                )}
                                                onChange={() =>
                                                    togglePlan(
                                                        plan.id,
                                                        plan.module,
                                                    )
                                                }
                                                className="mt-1"
                                                aria-label={`Select ${plan.name}`}
                                            />
                                            <div className="flex-1">
                                                <p className="font-medium">
                                                    {plan.name}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {plan.module} · catalogue ₹
                                                    {plan.annual_price}/yr ·
                                                    display list ₹
                                                    {plan.display_list_price}
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
                                        onChange={(e) =>
                                            setTenure(e.target.value)
                                        }
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        {tenureOptions.map((o) => (
                                            <option
                                                key={o.value}
                                                value={o.value}
                                            >
                                                {o.label} (−{o.discount_percent}
                                                %)
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
                                    />{' '}
                                    Renewal eligible (5%)
                                </label>
                                <div className="space-y-2">
                                    <Label htmlFor="coupon_percent">
                                        Coupon %
                                    </Label>
                                    <Input
                                        id="coupon_percent"
                                        value={couponPercent}
                                        onChange={(e) =>
                                            setCouponPercent(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="coupon_fixed">
                                        Coupon fixed ₹
                                    </Label>
                                    <Input
                                        id="coupon_fixed"
                                        value={couponFixed}
                                        onChange={(e) =>
                                            setCouponFixed(e.target.value)
                                        }
                                    />
                                </div>
                            </div>
                            <Button type="submit">Calculate on server</Button>
                        </form>
                        <aside className="h-fit space-y-4 rounded-xl border border-border p-4 xl:sticky xl:top-4">
                            <h2 className="font-semibold">
                                Server price breakdown
                            </h2>
                            {!breakdown ? (
                                <p className="text-sm text-muted-foreground">
                                    Select modules and calculate.
                                </p>
                            ) : (
                                <dl className="space-y-2 text-sm">
                                    {pricingBreakdownRows(breakdown).map(
                                        (row) => (
                                            <div
                                                key={row.key}
                                                className="flex justify-between gap-3"
                                            >
                                                <dt className="text-muted-foreground">
                                                    {row.label}
                                                </dt>
                                                <dd className="font-medium">
                                                    {row.value}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                            )}
                            {canManage && catalogue && planIds.length > 0 ? (
                                <Form
                                    action={links.create_draft}
                                    method="post"
                                    className="space-y-3 border-t border-border pt-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <h3 className="font-medium">
                                                Create draft contract
                                            </h3>
                                            <input
                                                type="hidden"
                                                name="catalogue_version_id"
                                                value={catalogue.id}
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
                                            <div className="space-y-2">
                                                <Label htmlFor="common_renewal_date">
                                                    Common renewal date
                                                </Label>
                                                <Input
                                                    id="common_renewal_date"
                                                    name="common_renewal_date"
                                                    type="date"
                                                    required
                                                />
                                                <InputError
                                                    message={
                                                        errors.common_renewal_date
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="remark">
                                                    Remark
                                                </Label>
                                                <Input
                                                    id="remark"
                                                    name="remark"
                                                    defaultValue="Create subscription draft contract"
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing
                                                    ? 'Creating…'
                                                    : 'Create draft (no payment)'}
                                            </Button>
                                            <p className="text-xs text-muted-foreground">
                                                Does not activate subscription
                                                or start Razorpay.
                                            </p>
                                        </>
                                    )}
                                </Form>
                            ) : null}
                        </aside>
                    </div>
                )}
            </div>
        </>
    );
}
