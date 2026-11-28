import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    ChangeReview,
    FieldReviewSection,
} from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDate } from '@/lib/dates';
import { freightRateTypeLabel } from '@/lib/freight-rate-type-options';
import {
    effectiveLineFreight,
    lineTransporterFreight,
    summarizeHireFinancials,
} from '@/lib/lorry-hire-financials';
import type { HireRateBasis } from '@/lib/lorry-hire-financials';
import { formatInr } from '@/lib/money';
import { formatMtLabel, kgToMt, parseNumeric } from '@/lib/operational-weight';
import {
    LrfActions,
    LrfPage,
    LrfSection,
    LrfSummaryMetrics,
} from '@/pages/branch/lorry-receipts/lr-form-sections';

type RateOption = { value: string; label: string };

type GoodsRow = HireRateBasis & {
    id: string;
    label: string;
};

type LineDraft = {
    lr_goods_row_id: string;
    rate_type: string;
    rate: string;
    custom_enabled: boolean;
    custom_amount: string;
};

type HireFormData = {
    hire_date: string;
    remarks: string;
    edit_remark: string;
    goods_lines: LineDraft[];
};

type RevisionLine = {
    lr_goods_row_id: string;
    rate_type: string;
    rate: string;
    custom_amount: string | null;
    freight_amount: string;
};

type RevisionSide = {
    hire_date: string | null;
    remarks: string | null;
    lines: RevisionLine[];
    freight_total: string;
    tds_amount: string;
    net_payable: string;
    outstanding_amount: string;
};

type RevisionPlan = {
    before: RevisionSide;
    after: RevisionSide;
    settled_amount: string;
    reserved_voucher_amount: string;
    tds_locked_reason: string | null;
    changed: boolean;
};

export type EditableHire = {
    id: string;
    hire_number: string;
    hire_date: string | null;
    remarks: string | null;
    goods_lines: Array<{
        lr_goods_row_id: string;
        rate_type: string;
        rate: string;
        custom_amount: string | null;
        calculated_freight_amount: string;
        freight_amount: string;
    }>;
    freight_total: string;
    tds_amount: string;
    net_payable: string;
    outstanding_amount: string;
    settled_amount: string;
    reserved_voucher_amount: string;
    tds_locked_reason: string | null;
    has_unloading_adjustment: boolean;
};

export type LorryHireFormProps = {
    lorryReceipt: {
        id: string;
        lr_number: string | null;
        lr_date: string | null;
        customer_name: string | null;
        vehicle_registration: string | null;
        vehicle_owner_name: string | null;
        loading_station: string | null;
        unloading_station: string | null;
    };
    payee: { id: string; name: string } | null;
    payeeBlockedReason: string | null;
    tdsPreview: { applicable_percent: string; explanation: string } | null;
    goodsRows: GoodsRow[];
    rateTypeOptions: RateOption[];
    defaults: { hire_date: string; rate_type: string };
    permissions: { can_create_voucher: boolean };
    hire?: EditableHire;
    urls: {
        store: string;
        lr_show: string;
        lr_edit: string;
        preview?: string;
    };
};

const SELECT_CLASS =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

const FIXED_RATE = 'fixed_amount';

function dash(value: string | null | undefined): string {
    return value !== null && value !== undefined && value !== '' ? value : '—';
}

function sumKgAsMtLabel(values: Array<string | null>): string {
    const present = values
        .map((value) => parseNumeric(value))
        .filter((value): value is number => value !== null);

    return present.length === 0
        ? '—'
        : formatMtLabel(kgToMt(present.reduce((sum, v) => sum + v, 0)));
}

function sumUnits(values: Array<string | null>): string {
    const present = values
        .map((value) => parseNumeric(value))
        .filter((value): value is number => value !== null);

    return present.length === 0
        ? '—'
        : String(present.reduce((sum, v) => sum + v, 0));
}

function rateUnitHint(rateType: string, semantics: string): string {
    switch (rateType) {
        case FIXED_RATE:
            return 'Total amount (₹)';
        case 'per_package_unit':
            return '₹ per unit';
        default:
            return semantics === 'weight_mt' ? '₹ per MT' : '₹ per KG';
    }
}

function money(value: string | number | null | undefined): string {
    return value === null || value === undefined || value === ''
        ? '—'
        : formatInr(value);
}

function ReadOnlyMetric({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0 space-y-0.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm font-medium break-words text-foreground">
                {value}
            </p>
        </div>
    );
}

function initialLines(
    goodsRows: GoodsRow[],
    defaultRateType: string,
    hire: EditableHire | undefined,
): LineDraft[] {
    return goodsRows.map((row) => {
        const existing = hire?.goods_lines.find(
            (line) => line.lr_goods_row_id === row.id,
        );

        return {
            lr_goods_row_id: row.id,
            rate_type: existing?.rate_type ?? defaultRateType,
            rate: existing?.rate ?? '',
            custom_enabled: (existing?.custom_amount ?? null) !== null,
            custom_amount: existing?.custom_amount ?? '',
        };
    });
}

/** Server revision plan → highlighted previous / proposed values for the confirmation. */
function revisionChanges(
    plan: RevisionPlan,
    goodsRows: GoodsRow[],
): ChangeReview[] {
    const changes: ChangeReview[] = [];
    const push = (
        label: string,
        previous: string,
        next: string,
        group: string,
    ) => {
        if (previous !== next) {
            changes.push({
                label,
                previous,
                next,
                changeType: 'modified',
                group,
            });
        }
    };

    push(
        'Hire Date',
        formatDisplayDate(plan.before.hire_date),
        formatDisplayDate(plan.after.hire_date),
        'Hire details',
    );
    push(
        'Remarks',
        dash(plan.before.remarks),
        dash(plan.after.remarks),
        'Hire details',
    );

    for (const line of plan.after.lines) {
        const previous = plan.before.lines.find(
            (row) => row.lr_goods_row_id === line.lr_goods_row_id,
        );
        const group =
            goodsRows.find((row) => row.id === line.lr_goods_row_id)?.label ??
            'Goods';
        push(
            'Rate Type',
            previous ? freightRateTypeLabel(previous.rate_type) : '—',
            freightRateTypeLabel(line.rate_type),
            group,
        );
        push(
            'Rate',
            previous ? Number(previous.rate).toString() : '—',
            Number(line.rate).toString(),
            group,
        );
        push(
            'Custom Amount',
            previous?.custom_amount ? formatInr(previous.custom_amount) : 'Off',
            line.custom_amount ? formatInr(line.custom_amount) : 'Off',
            group,
        );
        push(
            'Effective Freight',
            money(previous?.freight_amount),
            money(line.freight_amount),
            group,
        );
    }

    push(
        'Transporter Freight',
        money(plan.before.freight_total),
        money(plan.after.freight_total),
        'Financial impact',
    );
    push(
        'TDS',
        money(plan.before.tds_amount),
        money(plan.after.tds_amount),
        'Financial impact',
    );
    push(
        'Net Payable',
        money(plan.before.net_payable),
        money(plan.after.net_payable),
        'Financial impact',
    );
    push(
        'Outstanding Balance',
        money(plan.before.outstanding_amount),
        money(plan.after.outstanding_amount),
        'Financial impact',
    );

    return changes;
}

export default function LorryHireForm({
    mode,
    lorryReceipt,
    payee,
    payeeBlockedReason,
    tdsPreview,
    goodsRows,
    rateTypeOptions,
    defaults,
    permissions,
    hire,
    urls,
}: LorryHireFormProps & { mode: 'create' | 'edit' }) {
    const isEdit = mode === 'edit' && hire !== undefined;
    const form = useForm<HireFormData>({
        hire_date: hire?.hire_date ?? defaults.hire_date,
        remarks: hire?.remarks ?? '',
        edit_remark: '',
        goods_lines: initialLines(goodsRows, defaults.rate_type, hire),
    });
    const previewRequest = useHttp<Record<string, never>, RevisionPlan>({});
    const submitting = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [plan, setPlan] = useState<RevisionPlan | null>(null);

    const calculatedFreights = useMemo(
        () =>
            goodsRows.map((row, index) =>
                lineTransporterFreight(
                    form.data.goods_lines[index].rate_type,
                    form.data.goods_lines[index].rate,
                    row,
                ),
            ),
        [goodsRows, form.data.goods_lines],
    );
    const lineFreights = useMemo(
        () =>
            calculatedFreights.map((calculated, index) =>
                effectiveLineFreight(
                    calculated,
                    form.data.goods_lines[index].custom_enabled,
                    form.data.goods_lines[index].custom_amount,
                ),
            ),
        [calculatedFreights, form.data.goods_lines],
    );
    const lockedTds =
        isEdit && hire.tds_locked_reason !== null
            ? Number(hire.tds_amount)
            : null;
    const financials = useMemo(
        () =>
            summarizeHireFinancials(
                lineFreights,
                tdsPreview?.applicable_percent ?? null,
                lockedTds,
            ),
        [lineFreights, tdsPreview, lockedTds],
    );
    const adjustmentsNet = isEdit
        ? Number(hire.net_payable) -
          Number(hire.freight_total) +
          Number(hire.tds_amount)
        : 0;
    const netPayable = financials.netPayable + adjustmentsNet;

    const route =
        [lorryReceipt.loading_station, lorryReceipt.unloading_station]
            .filter(Boolean)
            .join(' → ') || '—';
    const goodsLabel = goodsRows.map((row) => row.label).join(', ') || '—';
    const unitsLabel = sumUnits(goodsRows.map((row) => row.package_count));
    const actualWeightLabel = sumKgAsMtLabel(
        goodsRows.map((row) => row.actual_weight),
    );
    const chargedWeightLabel = sumKgAsMtLabel(
        goodsRows.map((row) => row.charged_weight ?? row.actual_weight),
    );
    const tdsPercentLabel = tdsPreview
        ? `${tdsPreview.applicable_percent}%`
        : '—';
    const tdsAmountLabel =
        financials.tdsAmount !== null ? formatInr(financials.tdsAmount) : '—';

    const updateLine = (index: number, patch: Partial<LineDraft>) => {
        form.setData(
            'goods_lines',
            form.data.goods_lines.map((line, i) => {
                if (i !== index) {
                    return line;
                }
                const next = { ...line, ...patch };

                return next.rate_type === FIXED_RATE
                    ? { ...next, custom_enabled: false, custom_amount: '' }
                    : next;
            }),
        );
    };

    const payload = (data: HireFormData) => ({
        hire_date: data.hire_date,
        remarks: data.remarks,
        goods_lines: data.goods_lines.map((line) => ({
            lr_goods_row_id: line.lr_goods_row_id,
            rate_type: line.rate_type,
            rate: line.rate,
            custom_amount:
                line.custom_enabled && line.custom_amount !== ''
                    ? line.custom_amount
                    : null,
        })),
    });

    const createReviewSections = (): FieldReviewSection[] => [
        {
            title: 'Bilty summary',
            fields: [
                { label: 'Bilty Number', value: dash(lorryReceipt.lr_number) },
                {
                    label: 'Bilty Date',
                    value: formatDisplayDate(lorryReceipt.lr_date),
                },
                { label: 'Customer', value: dash(lorryReceipt.customer_name) },
                {
                    label: 'Truck Number',
                    value: dash(lorryReceipt.vehicle_registration),
                },
                { label: 'Payee', value: dash(payee?.name) },
                { label: 'Route', value: route },
            ],
        },
        {
            title: 'Hire details',
            layout: 'full',
            fields: [
                {
                    label: 'Hire Date',
                    value: formatDisplayDate(form.data.hire_date),
                },
                ...(form.data.remarks.trim() !== ''
                    ? [{ label: 'Remarks', value: form.data.remarks.trim() }]
                    : []),
            ],
            table: {
                headers: [
                    'Goods',
                    'Rate Type',
                    'Rate',
                    'Calculated',
                    'Custom Amount',
                    'Effective Freight',
                ],
                rows: goodsRows.map((row, index) => {
                    const line = form.data.goods_lines[index];

                    return [
                        row.label,
                        freightRateTypeLabel(line.rate_type),
                        dash(line.rate),
                        money(calculatedFreights[index]),
                        line.custom_enabled ? money(line.custom_amount) : 'Off',
                        money(lineFreights[index]),
                    ];
                }),
            },
        },
        {
            title: 'Financial summary',
            fields: [
                {
                    label: 'Transporter Freight',
                    value: formatInr(financials.transporterFreight),
                },
                {
                    label: `Applicable TDS (${tdsPercentLabel})`,
                    value: tdsAmountLabel,
                },
                {
                    label: 'Net Payable',
                    value: formatInr(financials.netPayable),
                    emphasis: 'total',
                },
            ],
        },
    ];

    const editSupplementarySections = (
        revision: RevisionPlan,
    ): FieldReviewSection[] => [
        {
            title: 'Payments',
            fields: [
                {
                    label: 'Paid / adjusted so far',
                    value: money(revision.settled_amount),
                },
                {
                    label: 'Reserved in pending vouchers',
                    value: money(revision.reserved_voucher_amount),
                },
                {
                    label: 'Revised Outstanding Balance',
                    value: money(revision.after.outstanding_amount),
                    emphasis: 'total',
                },
                ...(revision.tds_locked_reason
                    ? [{ label: 'TDS', value: revision.tds_locked_reason }]
                    : []),
            ],
        },
    ];

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (submitting.current || form.processing || payee === null) {
            return;
        }
        if (!isEdit) {
            setConfirmOpen(true);

            return;
        }
        if (form.data.edit_remark.trim() === '') {
            form.setError(
                'edit_remark',
                'Enter the reason for editing this Lorry Hire.',
            );

            return;
        }
        if (!urls.preview) {
            return;
        }
        form.clearErrors();
        previewRequest.transform(() => payload(form.data));
        void previewRequest.post(urls.preview, {
            onSuccess: (response) => {
                if (!response.changed) {
                    form.setError('goods_lines', 'No changes to save.');

                    return;
                }
                setPlan(response);
                setConfirmOpen(true);
            },
            onError: (errors) => {
                form.setError(
                    errors as Partial<Record<keyof HireFormData, string>>,
                );
            },
        });
    };

    const submitHire = (redirectToVoucher: boolean) => {
        if (submitting.current || form.processing) {
            return;
        }
        submitting.current = true;
        setConfirmOpen(false);
        const options = {
            preserveScroll: true,
            onFinish: () => {
                submitting.current = false;
            },
        };
        if (isEdit) {
            form.transform((data) => ({
                ...payload(data),
                edit_remark: data.edit_remark.trim(),
            }));
            form.put(urls.store, options);

            return;
        }
        form.transform((data) => ({
            ...payload(data),
            redirect_to_voucher: redirectToVoucher,
        }));
        form.post(urls.store, options);
    };

    const lineError = (
        index: number,
        field: 'rate_type' | 'rate' | 'custom_amount',
    ) =>
        (form.errors as Record<string, string | undefined>)[
            `goods_lines.${index}.${field}`
        ];

    const title = isEdit
        ? `Edit Lorry Hire ${hire.hire_number}`
        : 'Create Lorry Hire';
    const busy = form.processing || previewRequest.processing;

    return (
        <>
            <Head
                title={
                    lorryReceipt.lr_number
                        ? `${title} · ${lorryReceipt.lr_number}`
                        : title
                }
            />
            <LrfPage
                title={title}
                subtitle={
                    lorryReceipt.lr_number
                        ? `Freight booking from bilty ${lorryReceipt.lr_number}`
                        : 'Freight booking from linked bilty'
                }
                backHref={urls.lr_show}
                backLabel={isEdit ? 'Back to Lorry Hire' : 'Back to bilty'}
            >
                <LrfSection
                    step={1}
                    title="Bilty summary"
                    description={
                        isEdit
                            ? 'Inherited from the linked Bilty — change Payee, Truck or shipment details through Bilty edit'
                            : 'Inherited from the linked Bilty — read only'
                    }
                    tone="receipt"
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <ReadOnlyMetric
                            label="Bilty Number"
                            value={dash(lorryReceipt.lr_number)}
                        />
                        <ReadOnlyMetric
                            label="Bilty Date"
                            value={formatDisplayDate(lorryReceipt.lr_date)}
                        />
                        <ReadOnlyMetric
                            label="Customer"
                            value={dash(lorryReceipt.customer_name)}
                        />
                        <ReadOnlyMetric
                            label="Truck Number"
                            value={dash(lorryReceipt.vehicle_registration)}
                        />
                        <ReadOnlyMetric
                            label="Truck Owner"
                            value={dash(lorryReceipt.vehicle_owner_name)}
                        />
                        <ReadOnlyMetric
                            label="Payee"
                            value={dash(payee?.name)}
                        />
                        <ReadOnlyMetric label="Route" value={route} />
                        <ReadOnlyMetric label="Goods" value={goodsLabel} />
                        <ReadOnlyMetric label="Units" value={unitsLabel} />
                        <ReadOnlyMetric
                            label="Actual Weight"
                            value={actualWeightLabel}
                        />
                        <ReadOnlyMetric
                            label="Charged Weight"
                            value={chargedWeightLabel}
                        />
                    </div>
                </LrfSection>

                {payee === null ? (
                    <div
                        className="space-y-3 rounded-md border border-destructive/40 bg-destructive/5 p-4"
                        role="alert"
                    >
                        <p className="text-sm font-medium text-destructive">
                            Lorry Hire cannot be created yet.
                        </p>
                        <p className="text-sm text-foreground">
                            {payeeBlockedReason ??
                                'The Bilty Payee cannot be used for a Lorry Hire.'}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button asChild>
                                <Link href={urls.lr_edit}>Edit Bilty</Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={urls.lr_show}>Cancel</Link>
                            </Button>
                        </div>
                    </div>
                ) : (
                    <form
                        id="lorry-hire-form"
                        className="grid w-full gap-3"
                        onSubmit={handleSubmit}
                        noValidate={isEdit}
                    >
                        <LrfSection
                            step={2}
                            title="Hire details"
                            description="Transporter rate per goods line — Custom Amount replaces the calculated freight"
                            tone="goods"
                        >
                            <div className="grid gap-3 md:grid-cols-3">
                                <div className="space-y-1">
                                    <Label htmlFor="hire_date" required>
                                        Hire Date
                                    </Label>
                                    <Input
                                        id="hire_date"
                                        type="date"
                                        className="h-9"
                                        value={form.data.hire_date}
                                        onChange={(event) =>
                                            form.setData(
                                                'hire_date',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={form.errors.hire_date}
                                    />
                                </div>
                            </div>

                            <div className="mt-3 space-y-2">
                                {goodsRows.map((row, index) => {
                                    const draft = form.data.goods_lines[index];
                                    const isFixed =
                                        draft.rate_type === FIXED_RATE;
                                    const calculated =
                                        calculatedFreights[index];
                                    const effective = lineFreights[index];

                                    return (
                                        <div
                                            key={row.id}
                                            className="grid items-start gap-3 rounded-lg border border-border bg-muted/20 p-3 md:grid-cols-3 xl:grid-cols-[minmax(0,1.1fr)_minmax(10.5rem,1.1fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_minmax(0,1fr)_minmax(0,0.8fr)]"
                                        >
                                            <div className="min-w-0 space-y-0.5">
                                                <p className="text-xs text-muted-foreground">
                                                    Goods
                                                </p>
                                                <p className="text-sm font-medium break-words">
                                                    {row.label}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {dash(row.package_count)}{' '}
                                                    units ·{' '}
                                                    {sumKgAsMtLabel([
                                                        row.actual_weight,
                                                    ])}{' '}
                                                    actual ·{' '}
                                                    {sumKgAsMtLabel([
                                                        row.charged_weight ??
                                                            row.actual_weight,
                                                    ])}{' '}
                                                    charged
                                                </p>
                                            </div>

                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor={`rate_type_${index}`}
                                                    className="text-xs"
                                                    required
                                                >
                                                    Rate Type
                                                </Label>
                                                <select
                                                    id={`rate_type_${index}`}
                                                    className={SELECT_CLASS}
                                                    value={draft.rate_type}
                                                    onChange={(event) =>
                                                        updateLine(index, {
                                                            rate_type:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    required
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
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={lineError(
                                                        index,
                                                        'rate_type',
                                                    )}
                                                />
                                            </div>

                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor={`rate_${index}`}
                                                    className="text-xs"
                                                    required
                                                >
                                                    {isFixed
                                                        ? 'Amount'
                                                        : 'Rate'}
                                                </Label>
                                                <Input
                                                    id={`rate_${index}`}
                                                    type="number"
                                                    min="0"
                                                    step="0.0001"
                                                    className="h-9"
                                                    value={draft.rate}
                                                    onChange={(event) =>
                                                        updateLine(index, {
                                                            rate: event.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder={rateUnitHint(
                                                        draft.rate_type,
                                                        row.rate_semantics,
                                                    )}
                                                    required
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    {rateUnitHint(
                                                        draft.rate_type,
                                                        row.rate_semantics,
                                                    )}
                                                </p>
                                                <InputError
                                                    message={lineError(
                                                        index,
                                                        'rate',
                                                    )}
                                                />
                                            </div>

                                            <div className="space-y-1">
                                                <p className="text-xs font-medium text-muted-foreground">
                                                    Calculated Freight
                                                </p>
                                                <p
                                                    className={`flex h-9 items-center text-sm tabular-nums ${
                                                        draft.custom_enabled
                                                            ? 'text-muted-foreground line-through'
                                                            : 'font-medium'
                                                    }`}
                                                    data-testid={`calculated_freight_${index}`}
                                                >
                                                    {money(calculated)}
                                                </p>
                                            </div>

                                            <div className="space-y-1">
                                                <div className="flex h-4 items-center gap-2">
                                                    <Checkbox
                                                        id={`custom_enabled_${index}`}
                                                        checked={
                                                            draft.custom_enabled
                                                        }
                                                        disabled={isFixed}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            updateLine(index, {
                                                                custom_enabled:
                                                                    checked ===
                                                                    true,
                                                                custom_amount:
                                                                    checked ===
                                                                    true
                                                                        ? draft.custom_amount
                                                                        : '',
                                                            })
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`custom_enabled_${index}`}
                                                        className="text-xs"
                                                        required={
                                                            draft.custom_enabled
                                                        }
                                                    >
                                                        Custom Amount
                                                    </Label>
                                                </div>
                                                {draft.custom_enabled ? (
                                                    <Input
                                                        id={`custom_amount_${index}`}
                                                        aria-label="Custom Amount"
                                                        type="number"
                                                        min="0.01"
                                                        step="0.01"
                                                        className="h-9"
                                                        value={
                                                            draft.custom_amount
                                                        }
                                                        onChange={(event) =>
                                                            updateLine(index, {
                                                                custom_amount:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        placeholder="Enter freight (₹)"
                                                        required
                                                    />
                                                ) : (
                                                    <p className="flex h-9 items-center text-xs text-muted-foreground">
                                                        {isFixed
                                                            ? 'Not used with Fixed rate'
                                                            : 'Off — using calculated freight'}
                                                    </p>
                                                )}
                                                <InputError
                                                    message={lineError(
                                                        index,
                                                        'custom_amount',
                                                    )}
                                                />
                                            </div>

                                            <div className="space-y-1">
                                                <p className="text-xs font-medium text-muted-foreground">
                                                    Effective Freight
                                                </p>
                                                <p
                                                    className="flex h-9 items-center text-sm font-semibold tabular-nums"
                                                    data-testid={`effective_freight_${index}`}
                                                >
                                                    {money(effective)}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <InputError
                                message={form.errors.goods_lines}
                                className="mt-2"
                            />

                            <div className="mt-3 space-y-1">
                                <Label htmlFor="remarks">Remarks</Label>
                                <Input
                                    id="remarks"
                                    value={form.data.remarks}
                                    onChange={(event) =>
                                        form.setData(
                                            'remarks',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2000}
                                    placeholder="Optional remarks for this hire"
                                />
                                <InputError message={form.errors.remarks} />
                            </div>

                            {isEdit ? (
                                <div className="mt-3 space-y-1">
                                    <Label htmlFor="edit_remark" required>
                                        Edit Remark
                                    </Label>
                                    <Input
                                        id="edit_remark"
                                        value={form.data.edit_remark}
                                        onChange={(event) =>
                                            form.setData(
                                                'edit_remark',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={2000}
                                        placeholder="Why is this Lorry Hire being changed?"
                                        aria-invalid={
                                            Boolean(form.errors.edit_remark) ||
                                            undefined
                                        }
                                    />
                                    <InputError
                                        message={form.errors.edit_remark}
                                    />
                                </div>
                            ) : null}
                        </LrfSection>

                        <LrfSection
                            step={3}
                            title="Financial summary"
                            description={
                                isEdit
                                    ? 'Effective freight, TDS, payable and the revised balance after existing payments'
                                    : 'Transporter freight, applicable TDS and net payable'
                            }
                            tone="optional"
                        >
                            <LrfSummaryMetrics
                                items={[
                                    {
                                        name: 'Transporter Freight',
                                        value: formatInr(
                                            financials.transporterFreight,
                                        ),
                                    },
                                    {
                                        name: `Applicable TDS (${tdsPercentLabel})`,
                                        value: tdsAmountLabel,
                                    },
                                    ...(isEdit && adjustmentsNet !== 0
                                        ? [
                                              {
                                                  name: 'Freight Adjustments',
                                                  value: formatInr(
                                                      adjustmentsNet,
                                                  ),
                                              },
                                          ]
                                        : []),
                                    {
                                        name: 'Net Payable',
                                        value: formatInr(netPayable),
                                        hero: !isEdit,
                                    },
                                    ...(isEdit
                                        ? [
                                              {
                                                  name: 'Paid / adjusted',
                                                  value: money(
                                                      hire.settled_amount,
                                                  ),
                                              },
                                              {
                                                  name: 'Revised Outstanding',
                                                  value: formatInr(
                                                      netPayable -
                                                          Number(
                                                              hire.settled_amount,
                                                          ),
                                                  ),
                                                  hero: true,
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                            {isEdit && hire.tds_locked_reason ? (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {hire.tds_locked_reason}
                                </p>
                            ) : tdsPreview?.explanation ? (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {tdsPreview.explanation}
                                </p>
                            ) : null}
                            {isEdit && hire.has_unloading_adjustment ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    An unloading weight adjustment applies; the
                                    final freight is confirmed on review.
                                </p>
                            ) : null}
                        </LrfSection>

                        <LrfActions>
                            <Button
                                asChild
                                type="button"
                                variant="outline"
                                disabled={busy}
                                className="lrf-btn lrf-btn--outline"
                            >
                                <Link href={urls.lr_show}>Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={busy}
                                className="lrf-btn lrf-btn--brand"
                            >
                                {isEdit
                                    ? busy
                                        ? 'Checking…'
                                        : 'Review changes'
                                    : form.processing
                                      ? 'Creating…'
                                      : 'Create Lorry Hire'}
                            </Button>
                        </LrfActions>
                    </form>
                )}

                {isEdit ? (
                    <FormConfirmationDialog
                        open={confirmOpen && plan !== null}
                        onOpenChange={(open) => {
                            if (!open && form.processing) {
                                return;
                            }
                            setConfirmOpen(open);
                        }}
                        processing={form.processing}
                        mode="edit"
                        title="Save Lorry Hire changes?"
                        description="Previous and proposed values are recalculated by the server, including payments already recorded."
                        changes={
                            plan !== null
                                ? revisionChanges(plan, goodsRows)
                                : []
                        }
                        supplementarySections={
                            plan !== null ? editSupplementarySections(plan) : []
                        }
                        remark={form.data.edit_remark.trim()}
                        onConfirm={() => submitHire(false)}
                        confirmLabel="Save changes"
                    />
                ) : (
                    <FormConfirmationDialog
                        open={confirmOpen}
                        onOpenChange={(open) => {
                            if (!open && form.processing) {
                                return;
                            }
                            setConfirmOpen(open);
                        }}
                        processing={form.processing}
                        mode="create"
                        title="Create this Lorry Hire?"
                        description="Review the Bilty, hire details and financial summary before submitting."
                        sections={createReviewSections()}
                        onConfirm={() => submitHire(false)}
                        confirmLabel="Create Lorry Hire"
                        onSecondaryConfirm={
                            permissions.can_create_voucher
                                ? () => submitHire(true)
                                : undefined
                        }
                        secondaryConfirmLabel="Create & Record Voucher"
                    />
                )}
            </LrfPage>
        </>
    );
}
