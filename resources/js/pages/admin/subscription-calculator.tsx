import { Head, Link } from '@inertiajs/react';
import { Copy, Printer, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { getCsrfToken } from '@/lib/csrf';
import { formatInr } from '@/lib/money';

type PlanOption = {
    id: string;
    name: string;
    annual_price: string;
    capacity_units: number;
};

type Props = {
    modules: { value: string; label: string; plans: PlanOption[] }[];
    addons: { id: string; code: string; name: string; annual_price: string }[];
    tenures: { value: string; label: string; discount_percent: string }[];
    states: { id: string; name: string }[];
    included: { branches: number; users: number; storage_gb: number };
    links: { quote: string; catalogue: string };
};

type Inputs = {
    plans: Record<string, string>;
    addon_ids: string[];
    tenure: string;
    total_branches: string;
    total_users: string;
    broker_lr_credits: string;
    partload_lr_credits: string;
    total_storage_gb: string;
    state_id: string;
};

type LineItem = {
    item_type: 'plan' | 'addon';
    code: string;
    name: string;
    description: string;
    quantity: number;
    amount: string;
};

type CalculationResult = {
    quote: {
        payable: boolean;
        validation_message: string | null;
        tenure_label: string;
        line_items: LineItem[];
        subtotal_amount: string;
        discount_amount: string;
        taxable_amount: string;
        total_payable: string;
        discount_details: { bundle_percent: string; tenure_percent: string };
        included_capacity: {
            included_branches: number;
            included_seats: number;
            purchased_branches: number;
            purchased_seats: number;
            total_branches: number;
            total_users: number;
            lr_credits_broker: number;
            lr_credits_partload: number;
            storage_gb: number;
            ownfleet_vehicles: number | null;
        };
        gst: {
            configured: boolean;
            gst_rate_percent: string;
            cgst_amount: string;
            sgst_amount: string;
            igst_amount: string;
            total_tax_amount: string;
            is_inter_state: boolean;
        };
    };
    derived: { code: string; name: string; quantity: number }[];
    additional_charges: string;
    subscription_amount: string;
};

export default function SubscriptionCalculator({
    modules,
    addons,
    tenures,
    states,
    included,
    links,
}: Props) {
    const defaults: Inputs = useMemo(
        () => ({
            plans: {},
            addon_ids: [],
            tenure: 'yearly',
            total_branches: String(included.branches),
            total_users: String(included.users),
            broker_lr_credits: '',
            partload_lr_credits: '',
            total_storage_gb: String(included.storage_gb),
            state_id: '',
        }),
        [included],
    );
    const [inputs, setInputs] = useState<Inputs>(defaults);
    const [result, setResult] = useState<CalculationResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const selectedPlanIds = Object.values(inputs.plans).filter(Boolean);
    const hasBroker = Boolean(inputs.plans.broker);
    const hasPartload = Boolean(inputs.plans.partload);

    useEffect(() => {
        if (selectedPlanIds.length === 0) {
            setResult(null);
            setError(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            void fetch(links.quote, {
                method: 'POST',
                signal: controller.signal,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    plan_ids: selectedPlanIds,
                    addon_ids: inputs.addon_ids,
                    tenure: inputs.tenure,
                    total_branches: toInt(inputs.total_branches),
                    total_users: toInt(inputs.total_users),
                    broker_lr_credits: hasBroker
                        ? toInt(inputs.broker_lr_credits)
                        : null,
                    partload_lr_credits: hasPartload
                        ? toInt(inputs.partload_lr_credits)
                        : null,
                    total_storage_gb: toInt(inputs.total_storage_gb),
                    state_id: inputs.state_id || null,
                }),
            })
                .then(async (response) => {
                    const payload = (await response.json()) as
                        | CalculationResult
                        | {
                              message?: string;
                              errors?: Record<string, string[]>;
                          };
                    if (!response.ok) {
                        const failure = payload as {
                            message?: string;
                            errors?: Record<string, string[]>;
                        };
                        const firstError = failure.errors
                            ? Object.values(failure.errors)[0]?.[0]
                            : undefined;
                        setError(
                            firstError ??
                                failure.message ??
                                'Unable to calculate.',
                        );
                        setResult(null);

                        return;
                    }
                    setError(null);
                    setResult(payload as CalculationResult);
                })
                .catch((reason: unknown) => {
                    if (
                        reason instanceof DOMException &&
                        reason.name === 'AbortError'
                    ) {
                        return;
                    }
                    setError('Connection error. Check your network and retry.');
                })
                .finally(() => setLoading(false));
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(inputs)]);

    const update = <K extends keyof Inputs>(key: K, value: Inputs[K]) =>
        setInputs((current) => ({ ...current, [key]: value }));

    const copySummary = () => {
        if (!result) {
            return;
        }
        void navigator.clipboard
            .writeText(summaryText(result))
            .then(() => toast.success('Summary copied.'))
            .catch(() => toast.error('Unable to copy the summary.'));
    };

    return (
        <>
            <Head title="Subscription Calculator" />
            <PageShell
                title="Subscription Calculator"
                description="Estimate a subscription using the same pricing engine as Owner checkout. Nothing is saved, activated or charged."
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={links.catalogue}>
                            Subscription Catalogue
                        </Link>
                    </Button>
                }
            >
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)] print:block">
                    <div className="space-y-6 print:hidden">
                        <FormSection title="Modules" columns={3}>
                            {modules.map((module) => (
                                <div key={module.value} className="space-y-1.5">
                                    <Label htmlFor={`plan_${module.value}`}>
                                        {module.label}
                                    </Label>
                                    <select
                                        id={`plan_${module.value}`}
                                        value={inputs.plans[module.value] ?? ''}
                                        onChange={(e) =>
                                            update('plans', {
                                                ...inputs.plans,
                                                [module.value]: e.target.value,
                                            })
                                        }
                                        className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="">Not included</option>
                                        {module.plans.map((plan) => (
                                            <option
                                                key={plan.id}
                                                value={plan.id}
                                            >
                                                {plan.name} ·{' '}
                                                {formatInr(plan.annual_price)}
                                                /yr
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            ))}
                        </FormSection>

                        <FormSection title="Add-ons" columns={3}>
                            {addons.map((addon) => (
                                <label
                                    key={addon.id}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        className="mt-0.5 size-4 rounded border border-input"
                                        checked={inputs.addon_ids.includes(
                                            addon.id,
                                        )}
                                        onChange={(e) =>
                                            update(
                                                'addon_ids',
                                                e.target.checked
                                                    ? [
                                                          ...inputs.addon_ids,
                                                          addon.id,
                                                      ]
                                                    : inputs.addon_ids.filter(
                                                          (id) =>
                                                              id !== addon.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>
                                        {addon.name}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatInr(addon.annual_price)}/yr
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </FormSection>

                        <FormSection
                            title="Requirements"
                            description={`Included with every subscription: ${included.branches} branches, ${included.users} users and ${included.storage_gb} GB storage. Additional capacity is added automatically.`}
                            columns={3}
                        >
                            <div className="space-y-1.5">
                                <Label htmlFor="tenure" required>
                                    Billing Period
                                </Label>
                                <select
                                    id="tenure"
                                    value={inputs.tenure}
                                    onChange={(e) =>
                                        update('tenure', e.target.value)
                                    }
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {tenures.map((tenure) => (
                                        <option
                                            key={tenure.value}
                                            value={tenure.value}
                                        >
                                            {tenure.label}
                                            {Number(tenure.discount_percent) > 0
                                                ? ` (${tenure.discount_percent}% off)`
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <NumberField
                                id="total_branches"
                                label="Total Branches"
                                value={inputs.total_branches}
                                onChange={(v) => update('total_branches', v)}
                            />
                            <NumberField
                                id="total_users"
                                label="Total Users"
                                value={inputs.total_users}
                                onChange={(v) => update('total_users', v)}
                            />
                            {hasBroker ? (
                                <NumberField
                                    id="broker_lr_credits"
                                    label="Broker LR Credits / year"
                                    value={inputs.broker_lr_credits}
                                    onChange={(v) =>
                                        update('broker_lr_credits', v)
                                    }
                                />
                            ) : null}
                            {hasPartload ? (
                                <NumberField
                                    id="partload_lr_credits"
                                    label="Partload LR Credits / year"
                                    value={inputs.partload_lr_credits}
                                    onChange={(v) =>
                                        update('partload_lr_credits', v)
                                    }
                                />
                            ) : null}
                            <NumberField
                                id="total_storage_gb"
                                label="Total Storage (GB)"
                                value={inputs.total_storage_gb}
                                onChange={(v) => update('total_storage_gb', v)}
                            />
                            <div className="space-y-1.5">
                                <Label htmlFor="state_id">Customer State</Label>
                                <select
                                    id="state_id"
                                    value={inputs.state_id}
                                    onChange={(e) =>
                                        update('state_id', e.target.value)
                                    }
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">Same as supplier</option>
                                    {states.map((state) => (
                                        <option key={state.id} value={state.id}>
                                            {state.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </FormSection>
                    </div>

                    <aside
                        aria-labelledby="calculator-summary-heading"
                        aria-busy={loading}
                        className="h-fit space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs lg:sticky lg:top-4"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <h2
                                id="calculator-summary-heading"
                                className="font-semibold"
                            >
                                Summary
                            </h2>
                            {loading ? <Spinner className="size-4" /> : null}
                        </div>
                        <SummaryBody result={result} error={error} />
                        <div className="flex flex-wrap gap-2 print:hidden">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setInputs(defaults)}
                            >
                                <RotateCcw className="size-4" aria-hidden />
                                Reset
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={!result}
                                onClick={copySummary}
                            >
                                <Copy className="size-4" aria-hidden />
                                Copy Summary
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={!result}
                                onClick={() => window.print()}
                            >
                                <Printer className="size-4" aria-hidden />
                                Print
                            </Button>
                        </div>
                    </aside>
                </div>
            </PageShell>
        </>
    );
}

function NumberField({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="number"
                min="0"
                inputMode="numeric"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
}

function SummaryBody({
    result,
    error,
}: {
    result: CalculationResult | null;
    error: string | null;
}) {
    if (error) {
        return (
            <p role="alert" className="text-sm text-destructive">
                {error}
            </p>
        );
    }
    if (!result) {
        return (
            <p className="text-sm text-muted-foreground">
                Select at least one module to see the price.
            </p>
        );
    }

    const { quote } = result;
    const capacity = quote.included_capacity;
    const gst = quote.gst;
    const plans = quote.line_items.filter((i) => i.item_type === 'plan');
    const addonItems = quote.line_items.filter((i) => i.item_type === 'addon');

    return (
        <div className="space-y-4 text-sm" data-testid="calculator-summary">
            <SummaryList title="Modules" items={plans} />
            {addonItems.length > 0 ? (
                <SummaryList
                    title="Add-ons & Additional Capacity"
                    items={addonItems}
                />
            ) : null}
            <div>
                <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Capacity
                </h3>
                <dl className="mt-1 grid grid-cols-2 gap-x-3 gap-y-1">
                    <dt>Branches</dt>
                    <dd className="text-right">
                        {capacity.included_branches} included +{' '}
                        {capacity.purchased_branches} additional
                    </dd>
                    <dt>Users</dt>
                    <dd className="text-right">
                        {capacity.included_seats} included +{' '}
                        {capacity.purchased_seats} additional
                    </dd>
                    {capacity.lr_credits_broker > 0 ? (
                        <>
                            <dt>Broker LR credits</dt>
                            <dd className="text-right">
                                {capacity.lr_credits_broker.toLocaleString(
                                    'en-IN',
                                )}
                            </dd>
                        </>
                    ) : null}
                    {capacity.lr_credits_partload > 0 ? (
                        <>
                            <dt>Partload LR credits</dt>
                            <dd className="text-right">
                                {capacity.lr_credits_partload.toLocaleString(
                                    'en-IN',
                                )}
                            </dd>
                        </>
                    ) : null}
                    {capacity.ownfleet_vehicles ? (
                        <>
                            <dt>OwnFleet vehicles</dt>
                            <dd className="text-right">
                                {capacity.ownfleet_vehicles}
                            </dd>
                        </>
                    ) : null}
                    <dt>Storage</dt>
                    <dd className="text-right">{capacity.storage_gb} GB</dd>
                </dl>
            </div>
            <dl className="space-y-1 border-t border-border pt-3">
                <Row
                    label="Subscription Amount"
                    value={result.subscription_amount}
                />
                <Row
                    label="Additional Charges"
                    value={result.additional_charges}
                />
                <Row
                    label={`Discounts (multi-module ${quote.discount_details.bundle_percent}%, period ${quote.discount_details.tenure_percent}%)`}
                    value={`-${quote.discount_amount}`}
                />
                <Row label="Taxable Amount" value={quote.taxable_amount} />
                <div className="flex justify-between gap-3">
                    <dt>GST Rate</dt>
                    <dd>{Number(gst.gst_rate_percent)}%</dd>
                </div>
                {gst.is_inter_state ? (
                    <Row label="IGST" value={gst.igst_amount} />
                ) : (
                    <>
                        <Row label="CGST" value={gst.cgst_amount} />
                        <Row label="SGST" value={gst.sgst_amount} />
                    </>
                )}
                <Row label="GST Amount" value={gst.total_tax_amount} />
                <div className="flex justify-between gap-3 border-t border-border pt-2 text-base font-semibold">
                    <dt>Total Payable ({quote.tenure_label})</dt>
                    <dd data-testid="calculator-total">
                        {formatInr(quote.total_payable)}
                    </dd>
                </div>
            </dl>
            {!gst.configured ? (
                <p className="text-xs text-muted-foreground">
                    No active Billing Profile — GST is not applied.
                </p>
            ) : null}
            {quote.validation_message ? (
                <p className="text-xs text-destructive">
                    {quote.validation_message}
                </p>
            ) : null}
        </div>
    );
}

function SummaryList({ title, items }: { title: string; items: LineItem[] }) {
    return (
        <div>
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            <ul className="mt-1 space-y-1">
                {items.map((item) => (
                    <li
                        key={`${item.code}-${item.description}`}
                        className="flex justify-between gap-3"
                    >
                        <span>{item.description}</span>
                        <span className="shrink-0">
                            {formatInr(item.amount)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    const negative = value.startsWith('-');

    return (
        <div className="flex justify-between gap-3">
            <dt>{label}</dt>
            <dd>
                {negative ? '−' : ''}
                {formatInr(negative ? value.slice(1) : value)}
            </dd>
        </div>
    );
}

function toInt(value: string): number | null {
    const parsed = Number.parseInt(value, 10);

    return Number.isNaN(parsed) ? null : Math.max(0, parsed);
}

function summaryText(result: CalculationResult): string {
    const { quote } = result;
    const lines = [
        `TruckBill subscription estimate (${quote.tenure_label})`,
        ...quote.line_items.map(
            (item) => `- ${item.description}: ${formatInr(item.amount)}`,
        ),
        `Subscription amount: ${formatInr(result.subscription_amount)}`,
        `Additional charges: ${formatInr(result.additional_charges)}`,
        `Discounts: -${formatInr(quote.discount_amount)}`,
        `Taxable amount: ${formatInr(quote.taxable_amount)}`,
        `GST (${Number(quote.gst.gst_rate_percent)}%): ${formatInr(quote.gst.total_tax_amount)}`,
        `Total payable: ${formatInr(quote.total_payable)}`,
    ];

    return lines.join('\n');
}
