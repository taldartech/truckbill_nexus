import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { formatDisplayDate } from '@/lib/dates';
import {
    formatMtLabel,
    kgToMt,
    mtToKg,
    parseNumeric,
} from '@/lib/operational-weight';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RateOption = { value: string; label: string };

type GoodsRow = {
    id: string;
    goods_type_name: string | null;
    goods_description: string | null;
    package_count: string | null;
    actual_weight: string | null;
    charged_weight: string | null;
    customer_rate_type: string;
    customer_rate_type_label: string;
    customer_freight_rate: string | null;
    customer_freight_amount: string | null;
    suggested_loading_weight: string | null;
};

type Props = {
    lorryReceipt: {
        id: string;
        lr_number: string | null;
        lr_date: string | null;
        billing_customer_name: string | null;
        consignor_name: string | null;
        consignee_name: string | null;
        vehicle_registration: string | null;
        loading_station: string | null;
        unloading_station: string | null;
        customer_freight_total: string | null;
    };
    payee: {
        id: string;
        name: string;
        role_label: string;
        pan_status_label: string | null;
    } | null;
    transporterOptions: Array<{ id: string; name: string }>;
    suggestedPayeeId?: string | null;
    payeeRequired?: boolean;
    tdsPreview: {
        applicable_percent: string;
        explanation: string;
    } | null;
    goodsRows: GoodsRow[];
    rateTypeOptions: RateOption[];
    permissions: { can_view_freight: boolean };
    urls: {
        store: string;
        lr_show: string;
        index: string;
        parties_options?: string;
    };
};

type LineDraft = {
    loading_weight: string;
    rate_type: string;
    rate: string;
    is_fixed_amount: boolean;
};

function formatMoney(value: number | null): string {
    if (value === null) {
        return '—';
    }
    return value.toFixed(2);
}

function formatWeight(value: string | null): string {
    if (value === null || value.trim() === '') {
        return '—';
    }
    return value;
}

function formatWeightMt(value: string | null): string {
    return formatMtLabel(
        value === null || value.trim() === '' ? null : kgToMt(value),
    );
}

function goodsRowLabel(row: GoodsRow): string {
    if (row.goods_type_name) {
        return row.goods_description
            ? `${row.goods_type_name} — ${row.goods_description}`
            : row.goods_type_name;
    }
    return row.goods_description ?? 'Goods';
}

function computeTransporterFreightPreview(
    draft: LineDraft,
    row: GoodsRow,
): number | null {
    if (draft.is_fixed_amount || draft.rate_type === 'fixed_amount') {
        return parseNumeric(draft.rate);
    }

    const rate = parseNumeric(draft.rate);
    if (rate === null) {
        return null;
    }

    // UI draft.loading_weight is MT; convert for KG-based rate types.
    const loadingMt = parseNumeric(draft.loading_weight);
    const loadingKg = loadingMt === null ? null : loadingMt * 1000;
    const packages = parseNumeric(row.package_count);

    switch (draft.rate_type) {
        case 'fixed_amount':
            return rate;
        case 'per_package_unit':
            if (packages === null) {
                return null;
            }
            return rate * packages;
        case 'per_kg':
        case 'per_actual_weight':
        case 'per_charged_weight':
            if (loadingKg === null) {
                return null;
            }
            return rate * loadingKg;
        case 'per_mt':
            if (loadingMt === null) {
                return null;
            }
            return rate * loadingMt;
        default:
            return null;
    }
}

function sumPreviews(values: Array<number | null>): number {
    let total = 0;
    for (const value of values) {
        if (value !== null) {
            total += value;
        }
    }
    return total;
}

function fieldError(
    errors: Record<string, string | undefined>,
    key: string,
): string | undefined {
    return errors[key];
}

function SummaryRow({
    label,
    value,
    emphasis,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span
                className={
                    emphasis
                        ? 'font-semibold text-foreground tabular-nums'
                        : 'text-foreground tabular-nums'
                }
            >
                {value}
            </span>
        </div>
    );
}

function ReadOnlyMetric({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-0.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm text-foreground tabular-nums">{value}</p>
        </div>
    );
}

function initialTransporterPartyId(
    payee: Props['payee'],
    transporterOptions: Array<{ id: string; name: string }>,
    suggestedPayeeId?: string | null,
): string {
    if (
        suggestedPayeeId &&
        transporterOptions.some((option) => option.id === suggestedPayeeId)
    ) {
        return suggestedPayeeId;
    }
    if (payee && transporterOptions.some((option) => option.id === payee.id)) {
        return payee.id;
    }
    if (transporterOptions.length === 1) {
        return transporterOptions[0].id;
    }

    return '';
}

export default function LorryHireCreate({
    lorryReceipt,
    payee,
    transporterOptions,
    suggestedPayeeId = null,
    payeeRequired: payeeRequiredProp,
    tdsPreview,
    goodsRows,
    rateTypeOptions,
    permissions,
    urls,
}: Props) {
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const [transporterPartyId, setTransporterPartyId] = useState(() =>
        initialTransporterPartyId(payee, transporterOptions, suggestedPayeeId),
    );

    const [lineDrafts, setLineDrafts] = useState<LineDraft[]>(() =>
        goodsRows.map((row) => ({
            loading_weight: kgToMt(row.suggested_loading_weight),
            rate_type: 'per_mt',
            rate: '',
            is_fixed_amount: false,
        })),
    );

    const linePreviews = useMemo(
        () =>
            goodsRows.map((row, index) =>
                computeTransporterFreightPreview(lineDrafts[index], row),
            ),
        [goodsRows, lineDrafts],
    );

    const transporterFreightTotal = useMemo(
        () => sumPreviews(linePreviews),
        [linePreviews],
    );

    const customerFreightTotal = useMemo(() => {
        if (lorryReceipt.customer_freight_total) {
            const parsed = parseNumeric(lorryReceipt.customer_freight_total);
            if (parsed !== null) {
                return parsed;
            }
        }
        if (!permissions.can_view_freight) {
            return null;
        }
        return sumPreviews(
            goodsRows.map((row) => parseNumeric(row.customer_freight_amount)),
        );
    }, [
        goodsRows,
        lorryReceipt.customer_freight_total,
        permissions.can_view_freight,
    ]);

    const tdsPercentNumeric = parseNumeric(
        tdsPreview?.applicable_percent ?? null,
    );
    const tdsAmount =
        tdsPercentNumeric !== null
            ? (transporterFreightTotal * tdsPercentNumeric) / 100
            : null;
    const netPayable =
        tdsAmount !== null
            ? transporterFreightTotal - tdsAmount
            : transporterFreightTotal;
    const freightMargin =
        customerFreightTotal !== null
            ? customerFreightTotal - transporterFreightTotal
            : null;

    const updateLine = (index: number, patch: Partial<LineDraft>) => {
        setLineDrafts((current) =>
            current.map((line, i) => {
                if (i !== index) {
                    return line;
                }
                const next = { ...line, ...patch };
                if (patch.rate_type === 'fixed_amount') {
                    next.is_fixed_amount = true;
                }
                if (
                    patch.rate_type !== undefined &&
                    patch.rate_type !== 'fixed_amount'
                ) {
                    next.is_fixed_amount = false;
                }
                return next;
            }),
        );
    };

    const routeLabel = [
        lorryReceipt.loading_station,
        lorryReceipt.unloading_station,
    ]
        .filter(Boolean)
        .join(' → ');

    const selectedPayeeName =
        transporterOptions.find((option) => option.id === transporterPartyId)
            ?.name ??
        payee?.name ??
        '—';

    const openConfirmation = () => {
        const fields: FieldReview[] = [
            {
                label: 'Vehicle',
                value: lorryReceipt.vehicle_registration ?? '—',
            },
            {
                label: 'Payee / Transporter',
                value: selectedPayeeName,
            },
            {
                label: 'Goods lines',
                value: String(goodsRows.length),
            },
            {
                label: 'Total transporter freight',
                value: `₹${formatMoney(transporterFreightTotal)}`,
            },
            {
                label: 'TDS %',
                value:
                    tdsPreview?.applicable_percent !== undefined &&
                    tdsPreview.applicable_percent !== ''
                        ? `${tdsPreview.applicable_percent}%`
                        : '—',
            },
            {
                label: 'TDS amount',
                value: tdsAmount !== null ? `₹${formatMoney(tdsAmount)}` : '—',
            },
            {
                label: 'Net payable',
                value: `₹${formatMoney(netPayable)}`,
            },
        ];
        setReviewFields(fields);
        setConfirmOpen(true);
    };

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        if (!submitAllowed.current) {
            event.preventDefault();
            openConfirmation();
            return;
        }
        submitAllowed.current = false;
    };

    const handleConfirm = () => {
        submitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => {
            const form = document.getElementById(
                'lorry-hire-create-form',
            ) as HTMLFormElement | null;
            form?.requestSubmit();
        }, 0);
    };

    const payeeRequired = payeeRequiredProp ?? transporterOptions.length > 0;

    return (
        <>
            <Head
                title={
                    lorryReceipt.lr_number
                        ? `Create Lorry Hire · ${lorryReceipt.lr_number}`
                        : 'Create Lorry Hire'
                }
            />
            <PageShell
                width="form"
                title="Create Lorry Hire"
                description={
                    lorryReceipt.lr_number
                        ? `From bilty ${lorryReceipt.lr_number}`
                        : 'From linked bilty'
                }
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={urls.lr_show} className="inline-flex gap-2">
                            <ArrowLeft className="h-4 w-4" />
                            Back to bilty
                        </Link>
                    </Button>
                }
            >
                <Form
                    id="lorry-hire-create-form"
                    action={urls.store}
                    method="post"
                    className="grid w-full gap-4"
                    onSubmit={handleSubmit}
                >
                    {({ processing, errors }) => (
                        <>
                            <FormSection
                                title="Linked bilty"
                                description="Broker lorry receipt summary"
                                columns={2}
                            >
                                <p className="font-medium text-foreground md:col-span-2">
                                    {lorryReceipt.lr_number ?? '—'}
                                    {lorryReceipt.lr_date
                                        ? ` · ${formatDisplayDate(lorryReceipt.lr_date)}`
                                        : null}
                                </p>
                                <ReadOnlyMetric
                                    label="Billing customer"
                                    value={
                                        lorryReceipt.billing_customer_name ??
                                        '—'
                                    }
                                />
                                <ReadOnlyMetric
                                    label="Market vehicle"
                                    value={
                                        lorryReceipt.vehicle_registration ?? '—'
                                    }
                                />
                                <ReadOnlyMetric
                                    label="Consignor"
                                    value={lorryReceipt.consignor_name ?? '—'}
                                />
                                <ReadOnlyMetric
                                    label="Consignee"
                                    value={lorryReceipt.consignee_name ?? '—'}
                                />
                                <p className="text-sm text-muted-foreground md:col-span-2">
                                    {routeLabel || 'Route not set'}
                                </p>
                                {permissions.can_view_freight ? (
                                    <ReadOnlyMetric
                                        label="Customer freight total"
                                        value={
                                            customerFreightTotal !== null
                                                ? `₹${formatMoney(customerFreightTotal)}`
                                                : '—'
                                        }
                                    />
                                ) : null}
                            </FormSection>

                            <FormSection
                                title="Payee / transporter"
                                description="Select the transporter who will receive hire payment."
                                columns={2}
                            >
                                <div className="space-y-1 md:col-span-2">
                                    <SearchablePartySelect
                                        id="transporter_party_id"
                                        name="transporter_party_id"
                                        label="Payee / Transporter"
                                        options={transporterOptions}
                                        searchUrl={urls.parties_options}
                                        role="transporter"
                                        required={payeeRequired}
                                        value={transporterPartyId}
                                        onValueChange={setTransporterPartyId}
                                        emptyMessage="No active transporters are available. Add a transporter party before creating a Lorry Hire."
                                    />
                                    {payee?.role_label ? (
                                        <p className="text-xs">
                                            <span className="rounded-md border border-border bg-muted/40 px-2 py-0.5 font-medium text-foreground">
                                                {payee.role_label}
                                            </span>
                                            <span className="ml-2 text-muted-foreground">
                                                Suggested classification from
                                                linked Bilty vehicle owner
                                                context
                                            </span>
                                        </p>
                                    ) : null}
                                    {payee?.pan_status_label ? (
                                        <p className="text-xs text-muted-foreground">
                                            Suggested payee PAN status:{' '}
                                            {payee.pan_status_label}
                                        </p>
                                    ) : null}
                                </div>
                            </FormSection>

                            <FormSection
                                title="Hire charges"
                                description="Enter transporter rates per goods line. Customer freight is shown for reference only."
                                columns={1}
                            >
                                <div className="space-y-3">
                                    {goodsRows.map((row, index) => {
                                        const draft = lineDrafts[index];
                                        const isFixedRateType =
                                            draft.rate_type === 'fixed_amount';
                                        const preview = linePreviews[index];

                                        return (
                                            <div
                                                key={row.id}
                                                className="rounded-lg border border-border bg-muted/20 p-3"
                                            >
                                                <input
                                                    type="hidden"
                                                    name={`goods_lines[${index}][lr_goods_row_id]`}
                                                    value={row.id}
                                                />
                                                {isFixedRateType ? (
                                                    <input
                                                        type="hidden"
                                                        name={`goods_lines[${index}][is_fixed_amount]`}
                                                        value="1"
                                                    />
                                                ) : null}

                                                <div className="flex flex-wrap items-start justify-between gap-2">
                                                    <p className="text-sm font-medium text-foreground">
                                                        {goodsRowLabel(row)}
                                                    </p>
                                                    <span className="text-xs text-muted-foreground">
                                                        Line {index + 1}
                                                    </span>
                                                </div>

                                                <div className="mt-3 grid gap-3 md:grid-cols-3">
                                                    <ReadOnlyMetric
                                                        label="Packages"
                                                        value={formatWeight(
                                                            row.package_count,
                                                        )}
                                                    />
                                                    <ReadOnlyMetric
                                                        label="Actual weight (MT)"
                                                        value={formatWeightMt(
                                                            row.actual_weight,
                                                        )}
                                                    />
                                                    <ReadOnlyMetric
                                                        label="Charged weight (MT)"
                                                        value={formatWeightMt(
                                                            row.charged_weight,
                                                        )}
                                                    />
                                                    <ReadOnlyMetric
                                                        label="Customer rate type"
                                                        value={
                                                            row.customer_rate_type_label
                                                        }
                                                    />
                                                    {permissions.can_view_freight ? (
                                                        <>
                                                            <ReadOnlyMetric
                                                                label="Customer rate"
                                                                value={
                                                                    row.customer_freight_rate ??
                                                                    '—'
                                                                }
                                                            />
                                                            <ReadOnlyMetric
                                                                label="Customer amount"
                                                                value={
                                                                    row.customer_freight_amount
                                                                        ? `₹${row.customer_freight_amount}`
                                                                        : '—'
                                                                }
                                                            />
                                                        </>
                                                    ) : null}
                                                </div>

                                                <div className="mt-3 grid gap-3 border-t border-border pt-3 md:grid-cols-2 xl:grid-cols-4">
                                                    <div className="space-y-1">
                                                        <Label
                                                            htmlFor={`loading_weight_${index}`}
                                                            className="text-xs"
                                                        >
                                                            Loading weight (MT)
                                                        </Label>
                                                        <input
                                                            type="hidden"
                                                            name={`goods_lines[${index}][loading_weight]`}
                                                            value={mtToKg(
                                                                draft.loading_weight,
                                                            )}
                                                        />
                                                        <Input
                                                            id={`loading_weight_${index}`}
                                                            type="number"
                                                            min="0"
                                                            step="0.001"
                                                            className="h-9"
                                                            value={
                                                                draft.loading_weight
                                                            }
                                                            onChange={(event) =>
                                                                updateLine(
                                                                    index,
                                                                    {
                                                                        loading_weight:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={fieldError(
                                                                errors,
                                                                `goods_lines.${index}.loading_weight`,
                                                            )}
                                                        />
                                                    </div>

                                                    <div className="space-y-1">
                                                        <Label
                                                            htmlFor={`rate_type_${index}`}
                                                            className="text-xs"
                                                        >
                                                            Rate type
                                                        </Label>
                                                        <select
                                                            id={`rate_type_${index}`}
                                                            name={`goods_lines[${index}][rate_type]`}
                                                            className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            value={
                                                                draft.rate_type
                                                            }
                                                            onChange={(event) =>
                                                                updateLine(
                                                                    index,
                                                                    {
                                                                        rate_type:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {rateTypeOptions.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <InputError
                                                            message={
                                                                fieldError(
                                                                    errors,
                                                                    `goods_lines.${index}.rate_type`,
                                                                ) ??
                                                                fieldError(
                                                                    errors,
                                                                    `goods_lines.${index}.freight_rate_type`,
                                                                )
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1">
                                                        <Label
                                                            htmlFor={`rate_${index}`}
                                                            className="text-xs"
                                                        >
                                                            Transporter rate
                                                        </Label>
                                                        <Input
                                                            id={`rate_${index}`}
                                                            name={`goods_lines[${index}][rate]`}
                                                            type="number"
                                                            min="0"
                                                            step="0.0001"
                                                            className="h-9"
                                                            value={draft.rate}
                                                            onChange={(event) =>
                                                                updateLine(
                                                                    index,
                                                                    {
                                                                        rate: event
                                                                            .target
                                                                            .value,
                                                                    },
                                                                )
                                                            }
                                                            placeholder="Enter rate"
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                fieldError(
                                                                    errors,
                                                                    `goods_lines.${index}.rate`,
                                                                ) ??
                                                                fieldError(
                                                                    errors,
                                                                    `goods_lines.${index}.freight_rate`,
                                                                )
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Line freight preview
                                                        </Label>
                                                        <p className="flex h-9 items-center text-sm text-muted-foreground tabular-nums">
                                                            {preview === null
                                                                ? '—'
                                                                : `₹${formatMoney(preview)}`}
                                                        </p>
                                                    </div>

                                                    {!isFixedRateType ? (
                                                        <label
                                                            htmlFor={`is_fixed_amount_${index}`}
                                                            className="flex cursor-pointer items-center gap-2 md:col-span-2 xl:col-span-4"
                                                        >
                                                            <input
                                                                id={`is_fixed_amount_${index}`}
                                                                type="checkbox"
                                                                name={`goods_lines[${index}][is_fixed_amount]`}
                                                                value="1"
                                                                checked={
                                                                    draft.is_fixed_amount
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateLine(
                                                                        index,
                                                                        {
                                                                            is_fixed_amount:
                                                                                event
                                                                                    .target
                                                                                    .checked,
                                                                        },
                                                                    )
                                                                }
                                                                className="size-4 rounded border border-input"
                                                            />
                                                            <span className="text-sm">
                                                                Treat as fixed
                                                                freight amount
                                                            </span>
                                                        </label>
                                                    ) : null}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                <InputError message={errors.goods_lines} />
                            </FormSection>

                            <FormSection title="Financial summary" columns={1}>
                                <FinancialSummaryPanel
                                    canViewFreight={
                                        permissions.can_view_freight
                                    }
                                    customerFreightTotal={customerFreightTotal}
                                    transporterFreightTotal={
                                        transporterFreightTotal
                                    }
                                    tdsPercentLabel={
                                        tdsPreview?.applicable_percent ?? null
                                    }
                                    tdsAmount={tdsAmount}
                                    netPayable={netPayable}
                                    freightMargin={freightMargin}
                                    tdsExplanation={
                                        tdsPreview?.explanation ?? null
                                    }
                                />
                            </FormSection>

                            <FormSection title="Remarks" columns={1}>
                                <div className="space-y-1">
                                    <Label htmlFor="remarks">Remarks</Label>
                                    <Input
                                        id="remarks"
                                        name="remarks"
                                        placeholder="Optional remarks for this hire"
                                    />
                                    <InputError message={errors.remarks} />
                                </div>
                            </FormSection>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    {processing
                                        ? 'Creating…'
                                        : 'Create Lorry Hire'}
                                </Button>
                                <Button
                                    asChild
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    <Link href={urls.lr_show}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Create this Lorry Hire?"
                    description="Review vehicle, payee, rates, TDS, and net payable before submitting."
                    fields={reviewFields}
                    onConfirm={handleConfirm}
                />
            </PageShell>
        </>
    );
}

function FinancialSummaryPanel({
    canViewFreight,
    customerFreightTotal,
    transporterFreightTotal,
    tdsPercentLabel,
    tdsAmount,
    netPayable,
    freightMargin,
    tdsExplanation,
}: {
    canViewFreight: boolean;
    customerFreightTotal: number | null;
    transporterFreightTotal: number;
    tdsPercentLabel: string | null;
    tdsAmount: number | null;
    netPayable: number;
    freightMargin: number | null;
    tdsExplanation: string | null;
}) {
    return (
        <div className="space-y-2 rounded-md border border-border bg-muted/10 p-3">
            {canViewFreight ? (
                <SummaryRow
                    label="Total customer freight"
                    value={
                        customerFreightTotal !== null
                            ? `₹${formatMoney(customerFreightTotal)}`
                            : '—'
                    }
                />
            ) : null}
            <SummaryRow
                label="Total transporter freight"
                value={`₹${formatMoney(transporterFreightTotal)}`}
                emphasis
            />
            <SummaryRow
                label="TDS %"
                value={
                    tdsPercentLabel !== null && tdsPercentLabel !== ''
                        ? `${tdsPercentLabel}%`
                        : '—'
                }
            />
            <SummaryRow
                label="TDS amount"
                value={tdsAmount !== null ? `₹${formatMoney(tdsAmount)}` : '—'}
            />
            <SummaryRow
                label="Net payable"
                value={`₹${formatMoney(netPayable)}`}
                emphasis
            />
            {canViewFreight && freightMargin !== null ? (
                <SummaryRow
                    label="Gross freight margin"
                    value={`₹${formatMoney(freightMargin)}`}
                />
            ) : null}
            {canViewFreight &&
            customerFreightTotal !== null &&
            transporterFreightTotal > customerFreightTotal ? (
                <p
                    className="rounded-md border border-amber-500/40 bg-amber-500/10 px-2 py-1.5 text-xs font-medium text-amber-900"
                    role="status"
                >
                    Transporter freight exceeds customer freight. Confirm this
                    commercially intentional loss before creating the hire.
                </p>
            ) : null}
            {tdsExplanation ? (
                <p className="border-t border-border pt-2 text-xs text-muted-foreground">
                    {tdsExplanation}
                </p>
            ) : null}
        </div>
    );
}
