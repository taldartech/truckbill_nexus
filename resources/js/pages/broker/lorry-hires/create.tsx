import { Form, Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import { formatDisplayDate } from '@/lib/dates';
import { formatMtLabel, kgToMt, parseNumeric } from '@/lib/operational-weight';
import {
    LrfActions,
    LrfLinkedHero,
    LrfPage,
    LrfSection,
    LrfSummaryMetrics,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
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
        eway_bills?: Array<{
            ewb_number: string;
            ewb_date: string | null;
            valid_until: string | null;
        }>;
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
        parties_store?: string;
        parties_create?: string;
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

    const packages = parseNumeric(row.package_count);
    const actualMt = kgToMt(row.actual_weight);
    const chargedMt = kgToMt(row.charged_weight ?? row.actual_weight);
    const actualNum = parseNumeric(actualMt);
    const chargedNum = parseNumeric(chargedMt);

    switch (draft.rate_type) {
        case 'fixed_amount':
            return rate;
        case 'per_package_unit':
            if (packages === null) {
                return null;
            }
            return rate * packages;
        case 'per_actual_weight':
            if (actualNum === null) {
                return null;
            }
            return rate * actualNum;
        case 'per_charged_weight':
        case 'per_mt':
            if (chargedNum === null) {
                return null;
            }
            return rate * chargedNum;
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

function FormProcessingBridge({
    processing,
    onChange,
}: {
    processing: boolean;
    onChange: (value: boolean) => void;
}) {
    // Only notify on actual processing transitions. Inline onChange identities
    // change every render; re-firing onChange(false) was clearing submissionLock
    // between confirm and Inertia start (duplicate Hire POSTs).
    const previous = useRef<boolean | null>(null);
    useEffect(() => {
        if (previous.current === processing) {
            return;
        }
        previous.current = processing;
        onChange(processing);
    }, [onChange, processing]);
    return null;
}

function initialTransporterPartyId(
    payee: Props['payee'],
    transporterOptions: Array<{ id: string; name: string }>,
    suggestedPayeeId?: string | null,
): string {
    // Authoritative inherited payee from Bilty — do not require transporterOptions membership.
    if (payee?.id) {
        return payee.id;
    }
    if (
        suggestedPayeeId &&
        transporterOptions.some((option) => option.id === suggestedPayeeId)
    ) {
        return suggestedPayeeId;
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
    const submissionLock = useRef(false);
    const redirectToVoucher = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [formProcessing, setFormProcessing] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const transporterPartyId = useMemo(
        () =>
            initialTransporterPartyId(
                payee,
                transporterOptions,
                suggestedPayeeId,
            ),
        [payee, suggestedPayeeId, transporterOptions],
    );

    const [lineDrafts, setLineDrafts] = useState<LineDraft[]>(() =>
        goodsRows.map((row) => ({
            loading_weight: kgToMt(row.suggested_loading_weight),
            rate_type: 'per_charged_weight',
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

    const inheritedPayeeName = payee?.name ?? '—';
    const primaryGoods = goodsRows[0] ?? null;
    const goodsSummaryLabel = primaryGoods ? goodsRowLabel(primaryGoods) : '—';
    const actualWeightSummary =
        goodsRows.length === 1
            ? formatWeightMt(primaryGoods?.actual_weight ?? null)
            : `${goodsRows.length} lines`;
    const chargedWeightSummary =
        goodsRows.length === 1
            ? formatWeightMt(primaryGoods?.charged_weight ?? null)
            : `${goodsRows.length} lines`;
    const customerRateSummary = primaryGoods
        ? `${primaryGoods.customer_rate_type_label}${primaryGoods.customer_freight_rate ? ` @ ${primaryGoods.customer_freight_rate}` : ''}`
        : '—';
    const customerFreightSummary =
        permissions.can_view_freight && primaryGoods?.customer_freight_amount
            ? `₹${primaryGoods.customer_freight_amount}`
            : '—';

    const openConfirmation = () => {
        const fields: FieldReview[] = [
            {
                label: 'Vehicle',
                value: lorryReceipt.vehicle_registration ?? '—',
            },
            {
                label: 'Payee / Transporter',
                value: inheritedPayeeName,
                group: 'Parties',
            },
            {
                label: 'Bilty No.',
                value: lorryReceipt.lr_number ?? '—',
                group: 'Bilty',
            },
            {
                label: 'Bilty date',
                value: lorryReceipt.lr_date
                    ? formatDisplayDate(lorryReceipt.lr_date)
                    : '—',
                group: 'Bilty',
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

    const handleBeforeSubmit = (): boolean => {
        if (!submitAllowed.current) {
            if (!submissionLock.current && !formProcessing && !submitting) {
                openConfirmation();
            }
            return false;
        }
        // One deliberate confirmation → one submit; keep lock until response.
        submitAllowed.current = false;
        submissionLock.current = true;
        setSubmitting(true);
        return true;
    };

    const submitAfterConfirm = (continueToVoucher: boolean) => {
        if (submissionLock.current || formProcessing || submitting) {
            return;
        }
        submissionLock.current = true;
        setSubmitting(true);
        redirectToVoucher.current = continueToVoucher;
        submitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => {
            const form = document.getElementById(
                'lorry-hire-create-form',
            ) as HTMLFormElement | null;
            const voucherInput = form?.querySelector(
                '#redirect_to_voucher',
            ) as HTMLInputElement | null;
            if (voucherInput) {
                voucherInput.value = continueToVoucher ? '1' : '0';
            }
            if (form === null) {
                submissionLock.current = false;
                setSubmitting(false);
                return;
            }
            form.requestSubmit();
        }, 0);
    };

    const handleConfirm = () => {
        submitAfterConfirm(false);
    };

    const handleCreateClick = (
        event: React.MouseEvent<HTMLButtonElement>,
    ): void => {
        if (
            submitAllowed.current ||
            submissionLock.current ||
            formProcessing ||
            submitting
        ) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        const form = document.getElementById(
            'lorry-hire-create-form',
        ) as HTMLFormElement | null;
        if (form !== null && !form.reportValidity()) {
            return;
        }
        openConfirmation();
    };

    const handleConfirmAndRecordVoucher = () => {
        submitAfterConfirm(true);
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
            <LrfPage
                title="Create Lorry Hire"
                subtitle={
                    lorryReceipt.lr_number
                        ? `Freight booking from bilty ${lorryReceipt.lr_number}`
                        : 'Freight booking from linked bilty'
                }
                backHref={urls.lr_show}
                backLabel="Back to bilty"
            >
                <LrfLinkedHero
                    biltyNumber={lorryReceipt.lr_number ?? '—'}
                    routeFrom={
                        lorryReceipt.loading_station ??
                        routeLabel.split('→')[0]?.trim() ??
                        '—'
                    }
                    routeTo={
                        lorryReceipt.unloading_station ??
                        routeLabel.split('→')[1]?.trim() ??
                        '—'
                    }
                    payeeChip={payee?.role_label ?? null}
                    kpis={[
                        {
                            label: 'Bilty No.',
                            value: lorryReceipt.lr_number ?? '—',
                        },
                        {
                            label: 'Bilty date',
                            value: lorryReceipt.lr_date
                                ? formatDisplayDate(lorryReceipt.lr_date)
                                : '—',
                        },
                        {
                            label: 'Goods',
                            value: goodsSummaryLabel,
                        },
                        {
                            label: 'Actual weight',
                            value: actualWeightSummary,
                        },
                        {
                            label: 'Charged weight',
                            value: chargedWeightSummary,
                        },
                        {
                            label: 'Customer freight',
                            value:
                                permissions.can_view_freight &&
                                customerFreightTotal !== null
                                    ? `₹${formatMoney(customerFreightTotal)}`
                                    : '—',
                            accent: true,
                        },
                    ]}
                    parties={[
                        {
                            label: 'Customer',
                            value: lorryReceipt.billing_customer_name ?? '—',
                        },
                        {
                            label: 'Payee',
                            value: inheritedPayeeName,
                        },
                        {
                            label: 'Consignor',
                            value: lorryReceipt.consignor_name ?? '—',
                        },
                        {
                            label: 'Consignee',
                            value: lorryReceipt.consignee_name ?? '—',
                        },
                        {
                            label: 'Customer rate',
                            value: customerRateSummary,
                        },
                        {
                            label: 'Customer freight',
                            value: customerFreightSummary,
                        },
                    ]}
                    vehicleRoute={[
                        {
                            label: 'Truck',
                            value: lorryReceipt.vehicle_registration ?? '—',
                        },
                        {
                            label: 'TDS %',
                            value: tdsPreview?.applicable_percent
                                ? `${tdsPreview.applicable_percent}%`
                                : '—',
                        },
                        {
                            label: 'From',
                            value: lorryReceipt.loading_station ?? '—',
                        },
                        {
                            label: 'To',
                            value: lorryReceipt.unloading_station ?? '—',
                        },
                    ]}
                />

                <Form
                    id="lorry-hire-create-form"
                    action={urls.store}
                    method="post"
                    className="grid w-full gap-3"
                    onBefore={handleBeforeSubmit}
                >
                    {({ processing, errors }) => (
                        <>
                            <FormProcessingBridge
                                processing={processing}
                                onChange={(next) => {
                                    setFormProcessing((was) => {
                                        // Unlock only after a real Inertia round-trip ends.
                                        if (was && !next) {
                                            submissionLock.current = false;
                                            setSubmitting(false);
                                        }
                                        return next;
                                    });
                                }}
                            />
                            <input
                                type="hidden"
                                id="redirect_to_voucher"
                                name="redirect_to_voucher"
                                value={redirectToVoucher.current ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                id="transporter_party_id"
                                name="transporter_party_id"
                                value={transporterPartyId}
                            />

                            <LrfSection
                                step={1}
                                title="Payee"
                                description="Inherited from the linked Bilty — not reselected here"
                                tone="receipt"
                            >
                                <div className="space-y-2 md:col-span-2">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label>Payee</Label>
                                            <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm font-medium">
                                                {inheritedPayeeName}
                                            </div>
                                        </div>
                                        <div className="space-y-1">
                                            <Label>TDS %</Label>
                                            <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm font-medium tabular-nums">
                                                {tdsPreview?.applicable_percent
                                                    ? `${tdsPreview.applicable_percent}%`
                                                    : '—'}
                                            </div>
                                        </div>
                                    </div>
                                    {payee?.role_label ? (
                                        <p className="text-xs text-muted-foreground">
                                            {payee.role_label}
                                            {payee.pan_status_label
                                                ? ` · ${payee.pan_status_label}`
                                                : ''}
                                        </p>
                                    ) : null}
                                    {payeeRequired && !payee ? (
                                        <p
                                            className="text-xs text-destructive"
                                            role="alert"
                                        >
                                            No inherited payee is available for
                                            this bilty. Update the bilty payee
                                            before creating hire.
                                        </p>
                                    ) : null}
                                    <InputError
                                        message={errors.transporter_party_id}
                                    />
                                </div>
                            </LrfSection>

                            <LrfSection
                                step={2}
                                title="Hire Charges"
                                description="TR rate, amount, TDS and balance — customer freight stays independent"
                                tone="goods"
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
                                                    <ReadOnlyMetric
                                                        label="Actual weight (MT)"
                                                        value={formatWeightMt(
                                                            row.actual_weight,
                                                        )}
                                                    />
                                                    <ReadOnlyMetric
                                                        label="Charged weight (MT)"
                                                        value={formatWeightMt(
                                                            row.charged_weight ??
                                                                row.actual_weight,
                                                        )}
                                                    />
                                                    <ReadOnlyMetric
                                                        label="Units"
                                                        value={
                                                            row.package_count ??
                                                            '—'
                                                        }
                                                    />

                                                    <div className="space-y-1">
                                                        <Label
                                                            htmlFor={`rate_type_${index}`}
                                                            className="text-xs"
                                                            required
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
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                <InputError message={errors.goods_lines} />
                                <LrfSummaryMetrics
                                    items={[
                                        {
                                            name: 'TR Freight',
                                            value:
                                                transporterFreightTotal !== null
                                                    ? `₹${formatMoney(transporterFreightTotal)}`
                                                    : '—',
                                        },
                                        {
                                            name: 'TDS',
                                            value:
                                                tdsAmount !== null
                                                    ? `₹${formatMoney(tdsAmount)}`
                                                    : '—',
                                        },
                                        {
                                            name: 'Balance',
                                            value:
                                                netPayable !== null
                                                    ? `₹${formatMoney(netPayable)}`
                                                    : '—',
                                            hero: true,
                                        },
                                    ]}
                                />
                                {permissions.can_view_freight ? (
                                    <FinancialSummaryPanel
                                        canViewFreight={
                                            permissions.can_view_freight
                                        }
                                        customerFreightTotal={
                                            customerFreightTotal
                                        }
                                        transporterFreightTotal={
                                            transporterFreightTotal
                                        }
                                        tdsPercentLabel={
                                            tdsPreview?.applicable_percent ??
                                            null
                                        }
                                        tdsAmount={tdsAmount}
                                        netPayable={netPayable}
                                        freightMargin={freightMargin}
                                        tdsExplanation={
                                            tdsPreview?.explanation ?? null
                                        }
                                    />
                                ) : null}
                            </LrfSection>

                            <LrfSection
                                step={3}
                                title="Optional Details"
                                description="E-way bill and remarks"
                                tone="optional"
                                collapsible
                                defaultOpen={
                                    (lorryReceipt.eway_bills ?? []).length > 0
                                }
                            >
                                {(lorryReceipt.eway_bills ?? []).length ===
                                0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No E-Way Bills on this Bilty.
                                    </p>
                                ) : (
                                    <ul className="mb-3 space-y-2 text-sm">
                                        {(lorryReceipt.eway_bills ?? []).map(
                                            (row) => (
                                                <li
                                                    key={row.ewb_number}
                                                    className="rounded-md border border-border px-3 py-2"
                                                >
                                                    {row.ewb_number}
                                                    {row.ewb_date
                                                        ? ` · ${row.ewb_date}`
                                                        : ''}
                                                    {row.valid_until
                                                        ? ` · valid until ${row.valid_until}`
                                                        : ''}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                                <div className="space-y-1">
                                    <Label htmlFor="remarks">Remarks</Label>
                                    <Input
                                        id="remarks"
                                        name="remarks"
                                        placeholder="Optional remarks for this hire"
                                    />
                                    <InputError message={errors.remarks} />
                                </div>
                            </LrfSection>

                            <LrfActions>
                                <Button
                                    asChild
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    className="lrf-btn lrf-btn--outline"
                                >
                                    <Link href={urls.lr_show}>Cancel</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="lrf-btn lrf-btn--brand"
                                    onClick={handleCreateClick}
                                >
                                    {processing
                                        ? 'Creating…'
                                        : 'Create Lorry Hire'}
                                </Button>
                            </LrfActions>
                        </>
                    )}
                </Form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={(open) => {
                        if (!open && (submitting || formProcessing)) {
                            return;
                        }
                        setConfirmOpen(open);
                    }}
                    processing={formProcessing || submitting}
                    mode="create"
                    title="Create this Lorry Hire?"
                    description="Review vehicle, payee, rates, TDS, and net payable before submitting."
                    fields={reviewFields}
                    onConfirm={handleConfirm}
                    confirmLabel="Create Lorry Hire"
                    onSecondaryConfirm={handleConfirmAndRecordVoucher}
                    secondaryConfirmLabel="Create & Record Voucher"
                />
            </LrfPage>
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
