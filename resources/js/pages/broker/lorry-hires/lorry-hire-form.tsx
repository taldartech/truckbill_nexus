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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDate } from '@/lib/dates';
import { freightRateTypeLabel } from '@/lib/freight-rate-type-options';
import {
    customAmountsTotal,
    lineTransporterFreight,
    summarizeHireFinancials,
} from '@/lib/lorry-hire-financials';
import type { HireRateBasis } from '@/lib/lorry-hire-financials';
import { formatInr } from '@/lib/money';
import { formatMtLabel, kgToMt, parseNumeric } from '@/lib/operational-weight';
import { CustomAmountsSection } from '@/pages/broker/lorry-hires/components/custom-amounts-section';
import { LinkedBiltyContextPanel } from '@/pages/broker/lorry-hires/components/linked-bilty-context-panel';
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
};

type CustomAmountDraft = {
    id?: string;
    description: string;
    amount: string;
};

type HireFormData = {
    hire_date: string;
    remarks: string;
    edit_remark: string;
    goods_lines: LineDraft[];
    custom_amounts: CustomAmountDraft[];
};

type RevisionLine = {
    lr_goods_row_id: string;
    rate_type: string;
    rate: string;
    freight_amount: string;
};

type RevisionCustomAmount = {
    id: string | null;
    description: string;
    amount: string;
};

type RevisionSide = {
    hire_date: string | null;
    remarks: string | null;
    lines: RevisionLine[];
    custom_amounts: RevisionCustomAmount[];
    custom_amounts_total: string;
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
        freight_amount: string;
    }>;
    custom_amounts: CustomAmountDraft[];
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
        };
    });
}

function initialCustomAmounts(
    hire: EditableHire | undefined,
): CustomAmountDraft[] {
    return (hire?.custom_amounts ?? []).map((row) => ({
        id: row.id,
        description: row.description,
        amount: row.amount,
    }));
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
            'Transporter Freight',
            money(previous?.freight_amount),
            money(line.freight_amount),
            group,
        );
    }

    push(
        'Custom Amounts Total',
        formatInr(plan.before.custom_amounts_total),
        formatInr(plan.after.custom_amounts_total),
        'Custom Amounts',
    );

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
        custom_amounts: initialCustomAmounts(hire),
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
        () => calculatedFreights,
        [calculatedFreights],
    );
    const customTotal = useMemo(
        () => customAmountsTotal(form.data.custom_amounts),
        [form.data.custom_amounts],
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
                customTotal,
            ),
        [lineFreights, tdsPreview, lockedTds, customTotal],
    );
    const existingCustom = isEdit ? customAmountsTotal(hire.custom_amounts) : 0;
    const adjustmentsNet = isEdit
        ? Number(hire.net_payable) -
          Number(hire.freight_total) +
          Number(hire.tds_amount) -
          existingCustom
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
            form.data.goods_lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const addCustomAmountRow = () => {
        form.setData('custom_amounts', [
            ...form.data.custom_amounts,
            { description: '', amount: '' },
        ]);
    };

    const updateCustomAmount = (
        index: number,
        patch: Partial<CustomAmountDraft>,
    ) => {
        form.setData(
            'custom_amounts',
            form.data.custom_amounts.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ),
        );
    };

    const removeCustomAmount = (index: number) => {
        form.setData(
            'custom_amounts',
            form.data.custom_amounts.filter((_, i) => i !== index),
        );
    };

    const payload = (data: HireFormData) => ({
        hire_date: data.hire_date,
        remarks: data.remarks,
        goods_lines: data.goods_lines.map((line) => ({
            lr_goods_row_id: line.lr_goods_row_id,
            rate_type: line.rate_type,
            rate: line.rate,
        })),
        custom_amounts: data.custom_amounts
            .filter(
                (row) =>
                    row.description.trim() !== '' && row.amount.trim() !== '',
            )
            .map((row) => ({
                ...(row.id ? { id: row.id } : {}),
                description: row.description.trim(),
                amount: row.amount,
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
                headers: ['Goods', 'Rate Type', 'Rate', 'Transporter Freight'],
                rows: goodsRows.map((row, index) => {
                    const line = form.data.goods_lines[index];

                    return [
                        row.label,
                        freightRateTypeLabel(line.rate_type),
                        dash(line.rate),
                        money(lineFreights[index]),
                    ];
                }),
            },
        },
        ...(form.data.custom_amounts.length > 0
            ? [
                  {
                      title: 'Custom Amounts',
                      table: {
                          headers: ['Particulars', 'Amount (+/−)'],
                          rows: form.data.custom_amounts.map((row) => [
                              row.description.trim() || '—',
                              money(row.amount),
                          ]),
                      },
                  },
              ]
            : []),
        {
            title: 'Financial summary',
            fields: [
                {
                    label: 'Transporter Freight',
                    value: formatInr(financials.transporterFreight),
                },
                ...(customTotal !== 0
                    ? [
                          {
                              label: 'Custom Amounts',
                              value: formatInr(customTotal),
                          },
                      ]
                    : []),
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
                <LinkedBiltyContextPanel
                    lorryReceipt={lorryReceipt}
                    payeeName={payee?.name ?? null}
                    goodsLabel={goodsLabel}
                    unitsLabel={unitsLabel}
                    actualWeightLabel={actualWeightLabel}
                    chargedWeightLabel={chargedWeightLabel}
                    isEdit={isEdit}
                />

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
                        <div data-testid="lorry-hire-section-hire-charges">
                            <LrfSection
                                step={2}
                                title="Hire Charges"
                                description="TR rate, amount, TDS and balance per goods line from the linked Bilty"
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
                                        const draft =
                                            form.data.goods_lines[index];
                                        const isFixed =
                                            draft.rate_type === FIXED_RATE;
                                        const freight = lineFreights[index];

                                        return (
                                            <div
                                                key={row.id}
                                                className="grid items-start gap-3 rounded-lg border border-border bg-muted/20 p-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.2fr)_minmax(10.5rem,1fr)_minmax(0,0.9fr)_minmax(0,0.9fr)]"
                                            >
                                                <div className="min-w-0 space-y-0.5">
                                                    <p className="text-xs text-muted-foreground">
                                                        Goods
                                                    </p>
                                                    <p className="text-sm font-medium break-words">
                                                        {row.label}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {dash(
                                                            row.package_count,
                                                        )}{' '}
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
                                                                    {
                                                                        option.label
                                                                    }
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
                                                                rate: event
                                                                    .target
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
                                                        Transporter Freight
                                                    </p>
                                                    <p
                                                        className="flex h-9 items-center text-sm font-semibold tabular-nums"
                                                        data-testid={`line_freight_${index}`}
                                                    >
                                                        {money(freight)}
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

                                <div className="mt-4">
                                    <LrfSummaryMetrics
                                        items={[
                                            {
                                                name: 'TR Rate',
                                                value: money(
                                                    form.data.goods_lines[0]
                                                        ?.rate,
                                                ),
                                            },
                                            {
                                                name: 'TDS',
                                                value: tdsAmountLabel,
                                            },
                                            {
                                                name: 'Balance',
                                                value: formatInr(netPayable),
                                                hero: true,
                                            },
                                        ]}
                                    />
                                </div>
                            </LrfSection>
                        </div>

                        {isEdit ? (
                            <CustomAmountsSection
                                rows={form.data.custom_amounts}
                                total={customTotal}
                                formError={form.errors.custom_amounts}
                                onAdd={addCustomAmountRow}
                                onChange={updateCustomAmount}
                                onRemove={removeCustomAmount}
                            />
                        ) : null}

                        <div data-testid="lorry-hire-section-optional-details">
                            <LrfSection
                                step={isEdit ? 4 : 3}
                                title="Optional Details"
                                description="Remarks for this hire"
                                tone="optional"
                            >
                                <div className="space-y-1">
                                    <Label htmlFor="remarks">Remark</Label>
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
                                        placeholder="Optional"
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
                                                Boolean(
                                                    form.errors.edit_remark,
                                                ) || undefined
                                            }
                                        />
                                        <InputError
                                            message={form.errors.edit_remark}
                                        />
                                    </div>
                                ) : null}
                            </LrfSection>
                        </div>

                        <div data-testid="lorry-hire-section-financial-summary">
                            <LrfSection
                                step={isEdit ? 5 : 4}
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
                                        ...(customTotal !== 0
                                            ? [
                                                  {
                                                      name: 'Custom Amounts',
                                                      value: formatInr(
                                                          customTotal,
                                                      ),
                                                  },
                                              ]
                                            : []),
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
                                        An unloading weight adjustment applies;
                                        the final freight is confirmed on
                                        review.
                                    </p>
                                ) : null}
                            </LrfSection>
                        </div>

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
