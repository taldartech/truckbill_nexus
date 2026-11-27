import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    CreditCard,
    Minus,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDisplayDateSmart } from '@/lib/dates';

type Plan = {
    id: string;
    module: string;
    name: string;
    annual_price: string;
    capacity_units: number | null;
    subscribed: boolean;
};

type Addon = {
    id: string;
    code: string;
    name: string;
    annual_price: string;
    unit_label: string | null;
    is_quantifiable: boolean;
    credit_units_per_unit: number;
    storage_bytes_per_unit: number;
    subscribed: boolean;
};

type TenureOption = {
    value: string;
    label: string;
    months: number;
    discount_percent: string;
};

type QuoteLineItem = {
    item_type: string;
    id: string;
    name: string;
    description: string;
    quantity: number;
    amount: string;
    currency: string;
};

type Quote = {
    mode: 'initial' | 'upgrade' | 'renewal';
    payable: boolean;
    validation_message: string | null;
    currency: string;
    tenure: string;
    tenure_label: string;
    service_period_start: string;
    service_period_end: string;
    common_renewal_date: string;
    already_subscribed_modules: { module: string; name: string }[];
    already_subscribed_addons: {
        code: string;
        name: string;
        quantity: number;
    }[];
    line_items: QuoteLineItem[];
    subtotal_amount: string;
    discount_amount: string;
    taxable_amount: string;
    discount_details: {
        bundle_percent: string;
        tenure_percent: string;
        renewal_percent: string;
        mid_term_discount_percent: string;
        prorated: boolean;
        proration_days: number | null;
    };
    included_capacity: {
        total_branches: number;
        total_users: number;
        ownfleet_vehicles: number | null;
        lr_credits_broker: number;
        lr_credits_partload: number;
        storage_gb: number;
    };
    gst: {
        configured: boolean;
        rate_percent: string;
        cgst_amount: string;
        sgst_amount: string;
        igst_amount: string;
        total_tax_amount: string;
        place_of_supply_state: string | null;
        is_inter_state: boolean;
    };
    total_payable: string;
    billing_profile_configured: boolean;
};

type Props = {
    company: { id: string; name: string; status: string };
    companyOptions: {
        id: string;
        name: string;
        status: string;
        purchase_url: string;
    }[];
    canManage: boolean;
    lifecycle: {
        status: string;
        trial_ends_at: string | null;
        common_renewal_date: string | null;
    };
    plans: Plan[];
    addons: Addon[];
    tenureOptions: TenureOption[];
    initialQuote: Quote | null;
    customerBilling: {
        legal_name: string;
        registered_address: string | null;
        city: string | null;
        state: string | null;
        pin_code: string | null;
        gstin: string | null;
        pan: string | null;
        contact_number: string | null;
        email: string | null;
    };
    links: {
        quote: string;
        purchase: string;
        company_settings: string;
        payments: string;
        invoices: string;
        subscriptions: string;
    };
};

const MODULE_LABELS: Record<string, string> = {
    broker: 'Broker Operations',
    ownfleet: 'OwnFleet Management',
    partload: 'Partload Logistics',
};

function getCsrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1] ?? '') : '';
}

export default function SubscriptionPurchase({
    company,
    companyOptions,
    canManage,
    lifecycle,
    plans,
    addons,
    tenureOptions,
    initialQuote,
    customerBilling,
    links,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: { purchase?: string };
    }>().props;

    const isActivePaid = Boolean(lifecycle.common_renewal_date);
    const [mode, setMode] = useState<'initial' | 'upgrade' | 'renewal'>(
        initialQuote?.mode ?? (isActivePaid ? 'upgrade' : 'initial'),
    );
    const [selectedPlansByModule, setSelectedPlansByModule] = useState<
        Record<string, string>
    >(() => {
        const init: Record<string, string> = {};
        if (mode === 'renewal') {
            for (const plan of plans) {
                if (plan.subscribed) {
                    init[plan.module] = plan.id;
                }
            }
        }
        return init;
    });
    const [selectedAddonIds, setSelectedAddonIds] = useState<string[]>([]);
    const [addonQuantities, setAddonQuantities] = useState<
        Record<string, number>
    >({});
    const [tenure, setTenure] = useState<string>(
        initialQuote?.tenure ?? 'yearly',
    );
    const [quote, setQuote] = useState<Quote | null>(initialQuote);
    const [loadingQuote, setLoadingQuote] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const mountedRef = useRef(false);

    const activeModules = useMemo(() => {
        const set = new Set<string>();
        for (const p of plans) {
            if (p.subscribed) {
                set.add(p.module);
            }
        }
        for (const mod of Object.keys(selectedPlansByModule)) {
            if (selectedPlansByModule[mod]) {
                set.add(mod);
            }
        }
        return set;
    }, [plans, selectedPlansByModule]);

    const plansByModule = useMemo(() => {
        const grouped: Record<string, Plan[]> = {};
        for (const plan of plans) {
            grouped[plan.module] = grouped[plan.module] ?? [];
            grouped[plan.module].push(plan);
        }
        return grouped;
    }, [plans]);

    const selectedPlanIds = useMemo(
        () => Object.values(selectedPlansByModule).filter(Boolean),
        [selectedPlansByModule],
    );

    useEffect(() => {
        if (!mountedRef.current) {
            mountedRef.current = true;
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoadingQuote(true);
            setQuoteError(null);
            try {
                const res = await fetch(links.quote, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({
                        plan_ids: selectedPlanIds,
                        addon_ids: selectedAddonIds,
                        addon_quantities: addonQuantities,
                        tenure,
                        mode,
                    }),
                    signal: controller.signal,
                });
                const data = await res.json();
                if (!res.ok) {
                    setQuoteError(data.message ?? 'Unable to calculate quote.');
                } else {
                    setQuote(data);
                }
            } catch (err) {
                if ((err as Error).name !== 'AbortError') {
                    setQuoteError('Unable to refresh server pricing.');
                }
            } finally {
                setLoadingQuote(false);
            }
        }, 160);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [
        selectedPlanIds,
        selectedAddonIds,
        addonQuantities,
        tenure,
        mode,
        links.quote,
    ]);

    const togglePlan = (plan: Plan) => {
        if (plan.subscribed && mode !== 'renewal') {
            return;
        }
        setSelectedPlansByModule((prev) => {
            const current = prev[plan.module];
            if (current === plan.id) {
                const copy = { ...prev };
                delete copy[plan.module];
                return copy;
            }
            return { ...prev, [plan.module]: plan.id };
        });
    };

    const toggleAddon = (addon: Addon) => {
        if (addon.subscribed && mode !== 'renewal') {
            return;
        }
        setSelectedAddonIds((prev) => {
            if (prev.includes(addon.id)) {
                return prev.filter((id) => id !== addon.id);
            }
            if (addon.is_quantifiable && !(addon.id in addonQuantities)) {
                setAddonQuantities((q) => ({ ...q, [addon.id]: 1 }));
            }
            return [...prev, addon.id];
        });
    };

    const updateAddonQuantity = (addon: Addon, nextQty: number) => {
        const clamped = Math.max(0, Math.min(500, nextQty));
        setAddonQuantities((prev) => ({ ...prev, [addon.id]: clamped }));
        setSelectedAddonIds((prev) => {
            if (clamped > 0 && !prev.includes(addon.id)) {
                return [...prev, addon.id];
            }
            if (clamped === 0) {
                return prev.filter((id) => id !== addon.id);
            }
            return prev;
        });
    };

    const handleSubmit = () => {
        if (!canManage || !quote?.payable || submitting) {
            return;
        }
        setSubmitting(true);
        router.post(
            links.purchase,
            {
                plan_ids: selectedPlanIds,
                addon_ids: selectedAddonIds,
                addon_quantities: addonQuantities,
                tenure,
                mode,
            },
            {
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <Head title={`Subscribe · ${company.name}`} />
            <OwnerPageHeader
                title="Subscription Purchase"
                description="Select modules, optional add-ons, and billing period for the chosen company. All pricing and GST are calculated on the server."
                breadcrumbs={[
                    { label: 'Subscriptions', href: links.subscriptions },
                    { label: company.name },
                    { label: 'Purchase' },
                ]}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.payments}>Payment History</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={links.invoices}>Invoices</Link>
                        </Button>
                    </div>
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-2.5 text-sm font-medium"
                    >
                        {flash.success}
                    </p>
                ) : null}

                {errors?.purchase ? (
                    <p
                        className="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-2.5 text-sm text-destructive"
                        role="alert"
                    >
                        {errors.purchase}
                    </p>
                ) : null}

                {/* Explicit Company Selector (never relies on operational WorkspaceSession) */}
                <section className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-1 flex-col gap-1.5 sm:max-w-sm">
                        <Label htmlFor="purchase_company" required>
                            Purchasing Company
                        </Label>
                        <select
                            id="purchase_company"
                            required
                            value={company.id}
                            onChange={(e) => {
                                const target = companyOptions.find(
                                    (c) => c.id === e.target.value,
                                );
                                if (target) {
                                    router.visit(target.purchase_url);
                                }
                            }}
                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            {companyOptions.map((opt) => (
                                <option key={opt.id} value={opt.id}>
                                    {opt.name} ({opt.status})
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <Badge variant="outline" className="capitalize">
                            Status: {lifecycle.status.replaceAll('_', ' ')}
                        </Badge>
                        {lifecycle.trial_ends_at ? (
                            <Badge variant="secondary">
                                15-Day Trial ends{' '}
                                {formatDisplayDateSmart(
                                    lifecycle.trial_ends_at,
                                )}
                            </Badge>
                        ) : null}
                        {lifecycle.common_renewal_date ? (
                            <Badge variant="secondary">
                                Common Renewal:{' '}
                                {formatDisplayDateSmart(
                                    lifecycle.common_renewal_date,
                                )}
                            </Badge>
                        ) : null}
                        {isActivePaid ? (
                            <div className="inline-flex rounded-md border border-border bg-muted/40 p-0.5">
                                <button
                                    type="button"
                                    onClick={() => setMode('upgrade')}
                                    className={`rounded px-2.5 py-1 text-xs font-medium ${
                                        mode === 'upgrade'
                                            ? 'bg-background shadow-xs'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    Add Modules / Add-ons
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setMode('renewal')}
                                    className={`rounded px-2.5 py-1 text-xs font-medium ${
                                        mode === 'renewal'
                                            ? 'bg-background shadow-xs'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    Renew Subscription
                                </button>
                            </div>
                        ) : null}
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-12">
                    {/* Left Column: Modules, Add-ons, Period, Billing Info */}
                    <div className="space-y-6 lg:col-span-7 xl:col-span-8">
                        {/* Modules */}
                        <section className="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs">
                            <div>
                                <h2 className="text-base font-semibold">
                                    1. Select Modules
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    {mode === 'upgrade'
                                        ? 'Existing subscribed modules remain active at ₹0. New modules are prorated to your common renewal date.'
                                        : 'Choose one or more operational workflows for this company.'}
                                </p>
                            </div>

                            <div className="grid gap-3 md:grid-cols-3">
                                {['broker', 'ownfleet', 'partload'].map(
                                    (mod) => {
                                        const modPlans =
                                            plansByModule[mod] ?? [];
                                        if (modPlans.length === 0) {
                                            return null;
                                        }
                                        return (
                                            <div
                                                key={mod}
                                                className="flex flex-col gap-2 rounded-lg border border-border/80 bg-muted/15 p-3"
                                            >
                                                <p className="text-sm font-semibold">
                                                    {MODULE_LABELS[mod] ?? mod}
                                                </p>
                                                <div className="space-y-2">
                                                    {modPlans.map((plan) => {
                                                        const checked =
                                                            selectedPlansByModule[
                                                                mod
                                                            ] === plan.id ||
                                                            (plan.subscribed &&
                                                                mode !==
                                                                    'renewal');
                                                        return (
                                                            <button
                                                                key={plan.id}
                                                                type="button"
                                                                disabled={
                                                                    plan.subscribed &&
                                                                    mode !==
                                                                        'renewal'
                                                                }
                                                                onClick={() =>
                                                                    togglePlan(
                                                                        plan,
                                                                    )
                                                                }
                                                                className={`flex w-full items-start justify-between gap-2 rounded-md border p-2.5 text-left text-xs transition ${
                                                                    checked
                                                                        ? 'border-primary bg-primary/8'
                                                                        : 'border-border bg-background hover:border-primary/50'
                                                                } ${
                                                                    plan.subscribed &&
                                                                    mode !==
                                                                        'renewal'
                                                                        ? 'cursor-default opacity-80'
                                                                        : ''
                                                                }`}
                                                            >
                                                                <div className="space-y-0.5">
                                                                    <p className="font-medium text-foreground">
                                                                        {
                                                                            plan.name
                                                                        }
                                                                    </p>
                                                                    {plan.capacity_units ? (
                                                                        <p className="text-[11px] text-muted-foreground">
                                                                            {mod ===
                                                                            'ownfleet'
                                                                                ? `Up to ${plan.capacity_units} vehicles`
                                                                                : `${plan.capacity_units} LR credits/yr`}
                                                                        </p>
                                                                    ) : null}
                                                                    {plan.subscribed ? (
                                                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                                                                            <CheckCircle2 className="h-3 w-3" />
                                                                            Subscribed
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                                <span className="font-semibold whitespace-nowrap">
                                                                    ₹
                                                                    {
                                                                        plan.annual_price
                                                                    }
                                                                    /yr
                                                                </span>
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        </section>

                        {/* Add-ons */}
                        <section className="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs">
                            <div>
                                <h2 className="text-base font-semibold">
                                    2. Optional Add-ons
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    Accounting is an optional paid add-on and is
                                    never auto-enabled without purchase.
                                </p>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                {addons.map((addon) => {
                                    const requiresBroker =
                                        addon.code === 'lr_credits_broker' &&
                                        !activeModules.has('broker');
                                    const requiresPartload =
                                        addon.code === 'lr_credits_partload' &&
                                        !activeModules.has('partload');
                                    const disabledCredit =
                                        requiresBroker || requiresPartload;
                                    const isSelected =
                                        selectedAddonIds.includes(addon.id) ||
                                        (addon.subscribed &&
                                            mode !== 'renewal');
                                    const qty = addonQuantities[addon.id] ?? 0;

                                    return (
                                        <div
                                            key={addon.id}
                                            className={`flex flex-col justify-between gap-2 rounded-lg border p-3 ${
                                                isSelected
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border'
                                            } ${disabledCredit ? 'opacity-55' : ''}`}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="space-y-0.5">
                                                    <div className="flex items-center gap-1.5">
                                                        <p className="text-sm font-medium">
                                                            {addon.name}
                                                        </p>
                                                        {addon.code ===
                                                        'accounting' ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[10px]"
                                                            >
                                                                Optional GL
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        ₹{addon.annual_price}
                                                        {addon.unit_label
                                                            ? ` / ${addon.unit_label}`
                                                            : '/yr'}
                                                    </p>
                                                    {addon.subscribed ? (
                                                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                                                            <CheckCircle2 className="h-3 w-3" />
                                                            Already active
                                                        </span>
                                                    ) : null}
                                                    {disabledCredit ? (
                                                        <p className="text-[11px] text-amber-700 dark:text-amber-400">
                                                            Requires{' '}
                                                            {requiresBroker
                                                                ? 'Broker'
                                                                : 'Partload'}{' '}
                                                            module
                                                        </p>
                                                    ) : null}
                                                </div>

                                                {addon.is_quantifiable ? (
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            disabled={
                                                                disabledCredit ||
                                                                qty <= 0
                                                            }
                                                            onClick={() =>
                                                                updateAddonQuantity(
                                                                    addon,
                                                                    qty - 1,
                                                                )
                                                            }
                                                        >
                                                            <Minus className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <span className="w-7 text-center text-sm font-medium">
                                                            {qty}
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-7 w-7"
                                                            disabled={
                                                                disabledCredit
                                                            }
                                                            onClick={() =>
                                                                updateAddonQuantity(
                                                                    addon,
                                                                    qty + 1,
                                                                )
                                                            }
                                                        >
                                                            <Plus className="h-3.5 w-3.5" />
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant={
                                                            isSelected
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                        disabled={
                                                            addon.subscribed &&
                                                            mode !== 'renewal'
                                                        }
                                                        onClick={() =>
                                                            toggleAddon(addon)
                                                        }
                                                    >
                                                        {addon.subscribed &&
                                                        mode !== 'renewal'
                                                            ? 'Active'
                                                            : isSelected
                                                              ? 'Selected'
                                                              : 'Add'}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>

                        {/* Subscription Period */}
                        <section className="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs">
                            <div>
                                <h2 className="text-base font-semibold">
                                    3. Subscription Period
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    {mode === 'upgrade'
                                        ? 'Upgrades align automatically to your existing common renewal date.'
                                        : 'Approved billing periods with applicable tenure discounts.'}
                                </p>
                            </div>

                            {mode === 'upgrade' ? (
                                <p className="rounded-md border border-border bg-muted/30 px-3 py-2 text-xs">
                                    Aligned to Common Renewal Date:{' '}
                                    <strong>
                                        {formatDisplayDateSmart(
                                            lifecycle.common_renewal_date,
                                        )}
                                    </strong>
                                </p>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-3">
                                    {tenureOptions.map((opt) => (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => setTenure(opt.value)}
                                            className={`flex flex-col items-start rounded-lg border p-2.5 text-left text-xs transition ${
                                                tenure === opt.value
                                                    ? 'border-primary bg-primary/8'
                                                    : 'border-border hover:border-primary/50'
                                            }`}
                                        >
                                            <span className="font-semibold">
                                                {opt.label}
                                            </span>
                                            <span className="text-[11px] text-muted-foreground">
                                                {Number(opt.discount_percent) >
                                                0
                                                    ? `${opt.discount_percent}% tenure discount`
                                                    : `${opt.months} mo`}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* Customer Billing Details Review */}
                        <section className="space-y-2 rounded-xl border border-border bg-card p-4 shadow-xs">
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="text-base font-semibold">
                                    4. Customer Billing Details
                                </h2>
                                <Link
                                    href={links.company_settings}
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    Edit in Company Settings
                                </Link>
                            </div>
                            <dl className="grid gap-2 text-xs sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Legal Company Name
                                    </dt>
                                    <dd className="font-medium">
                                        {customerBilling.legal_name}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        GSTIN / PAN
                                    </dt>
                                    <dd className="font-mono">
                                        {customerBilling.gstin ||
                                            'Unregistered'}
                                        {customerBilling.pan
                                            ? ` · ${customerBilling.pan}`
                                            : ''}
                                    </dd>
                                </div>
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground">
                                        Registered Address
                                    </dt>
                                    <dd>
                                        {[
                                            customerBilling.registered_address,
                                            customerBilling.city,
                                            customerBilling.state,
                                            customerBilling.pin_code,
                                        ]
                                            .filter(Boolean)
                                            .join(', ') ||
                                            'Update company address in Company Settings if needed.'}
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    {/* Right Column (Desktop Side-by-Side / Mobile Below): Live Authoritative Pricing Summary */}
                    <aside className="lg:col-span-5 xl:col-span-4">
                        <div className="sticky top-4 space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm">
                            <div className="flex items-center justify-between border-b border-border pb-3">
                                <h2 className="text-base font-semibold">
                                    Purchase Summary
                                </h2>
                                {loadingQuote ? (
                                    <span className="text-xs text-muted-foreground">
                                        Calculating…
                                    </span>
                                ) : null}
                            </div>

                            {quoteError ? (
                                <p
                                    className="text-xs text-destructive"
                                    role="alert"
                                >
                                    {quoteError}
                                </p>
                            ) : null}

                            {quote ? (
                                <>
                                    {quote.line_items.length > 0 ? (
                                        <ul className="space-y-2 border-b border-border pb-3 text-xs">
                                            {quote.line_items.map((item) => (
                                                <li
                                                    key={`${item.item_type}-${item.id}`}
                                                    className="flex items-start justify-between gap-2"
                                                >
                                                    <span>
                                                        {item.description}
                                                    </span>
                                                    <span className="font-medium whitespace-nowrap">
                                                        ₹{item.amount}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            {quote.validation_message ??
                                                'Select modules or add-ons to preview pricing.'}
                                        </p>
                                    )}

                                    <dl className="space-y-1.5 border-b border-border pb-3 text-xs">
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                Subscription Period
                                            </dt>
                                            <dd className="font-medium">
                                                {quote.tenure_label}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                Renewal / Expiry Date
                                            </dt>
                                            <dd className="font-medium">
                                                {formatDisplayDateSmart(
                                                    quote.common_renewal_date,
                                                )}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                Included Capacity
                                            </dt>
                                            <dd className="text-right font-medium">
                                                {
                                                    quote.included_capacity
                                                        .total_branches
                                                }{' '}
                                                branches ·{' '}
                                                {
                                                    quote.included_capacity
                                                        .total_users
                                                }{' '}
                                                users
                                                {quote.included_capacity
                                                    .ownfleet_vehicles
                                                    ? ` · ${quote.included_capacity.ownfleet_vehicles} vehicles`
                                                    : ''}
                                            </dd>
                                        </div>
                                        {quote.included_capacity
                                            .lr_credits_broker > 0 ||
                                        quote.included_capacity
                                            .lr_credits_partload > 0 ? (
                                            <div className="flex justify-between">
                                                <dt className="text-muted-foreground">
                                                    LR / Bilty Credits
                                                </dt>
                                                <dd className="font-medium">
                                                    {quote.included_capacity
                                                        .lr_credits_broker > 0
                                                        ? `Broker ${quote.included_capacity.lr_credits_broker} `
                                                        : ''}
                                                    {quote.included_capacity
                                                        .lr_credits_partload > 0
                                                        ? `Partload ${quote.included_capacity.lr_credits_partload}`
                                                        : ''}
                                                </dd>
                                            </div>
                                        ) : null}
                                    </dl>

                                    <dl className="space-y-1.5 text-xs">
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                Base Amount
                                            </dt>
                                            <dd>₹{quote.subtotal_amount}</dd>
                                        </div>
                                        <div className="flex justify-between text-emerald-700 dark:text-emerald-400">
                                            <dt>Applicable Discounts</dt>
                                            <dd>- ₹{quote.discount_amount}</dd>
                                        </div>
                                        <div className="flex justify-between font-medium">
                                            <dt>Taxable Amount</dt>
                                            <dd>₹{quote.taxable_amount}</dd>
                                        </div>
                                        {quote.gst.configured ? (
                                            quote.gst.is_inter_state ? (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        IGST (
                                                        {quote.gst.rate_percent}
                                                        %)
                                                    </dt>
                                                    <dd>
                                                        ₹{quote.gst.igst_amount}
                                                    </dd>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="flex justify-between">
                                                        <dt className="text-muted-foreground">
                                                            CGST (
                                                            {Number(
                                                                quote.gst
                                                                    .rate_percent,
                                                            ) / 2}
                                                            %)
                                                        </dt>
                                                        <dd>
                                                            ₹
                                                            {
                                                                quote.gst
                                                                    .cgst_amount
                                                            }
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between">
                                                        <dt className="text-muted-foreground">
                                                            SGST (
                                                            {Number(
                                                                quote.gst
                                                                    .rate_percent,
                                                            ) / 2}
                                                            %)
                                                        </dt>
                                                        <dd>
                                                            ₹
                                                            {
                                                                quote.gst
                                                                    .sgst_amount
                                                            }
                                                        </dd>
                                                    </div>
                                                </>
                                            )
                                        ) : (
                                            <p className="text-[11px] text-amber-700 dark:text-amber-400">
                                                Platform GST billing profile not
                                                yet configured.
                                            </p>
                                        )}
                                        <div className="flex items-center justify-between border-t border-border pt-2 text-base font-bold">
                                            <dt>Total Payable</dt>
                                            <dd>
                                                {quote.currency}{' '}
                                                {quote.total_payable}
                                            </dd>
                                        </div>
                                    </dl>
                                </>
                            ) : null}

                            <Button
                                type="button"
                                className="w-full"
                                disabled={
                                    !canManage ||
                                    !quote?.payable ||
                                    loadingQuote ||
                                    submitting
                                }
                                onClick={handleSubmit}
                            >
                                <CreditCard className="mr-2 h-4 w-4" />
                                {submitting
                                    ? 'Preparing Checkout…'
                                    : 'Review & Pay'}
                            </Button>

                            <p className="flex items-start gap-1.5 text-[11px] text-muted-foreground">
                                <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                Entitlements activate immediately after server
                                payment verification; your invoice enters
                                Pending Approval until finalized.
                            </p>
                        </div>
                    </aside>
                </div>
            </OwnerPageHeader>
        </>
    );
}
