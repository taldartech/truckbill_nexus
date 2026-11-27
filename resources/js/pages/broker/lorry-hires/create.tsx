import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReviewSection } from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDate } from '@/lib/dates';
import { freightRateTypeLabel } from '@/lib/freight-rate-type-options';
import {
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

type LineDraft = { rate_type: string; rate: string };

type Props = {
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
    urls: { store: string; lr_show: string; lr_edit: string };
};

const SELECT_CLASS =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

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
        case 'fixed_amount':
            return 'Total amount (₹)';
        case 'per_package_unit':
            return '₹ per unit';
        default:
            return semantics === 'weight_mt' ? '₹ per MT' : '₹ per KG';
    }
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

export default function LorryHireCreate({
    lorryReceipt,
    payee,
    payeeBlockedReason,
    tdsPreview,
    goodsRows,
    rateTypeOptions,
    defaults,
    permissions,
    urls,
}: Props) {
    const form = useForm({
        hire_date: defaults.hire_date,
        remarks: '',
        goods_lines: goodsRows.map(
            (row): LineDraft & { lr_goods_row_id: string } => ({
                lr_goods_row_id: row.id,
                rate_type: defaults.rate_type,
                rate: '',
            }),
        ),
    });
    const submitting = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const lineFreights = useMemo(
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
    const financials = useMemo(
        () =>
            summarizeHireFinancials(
                lineFreights,
                tdsPreview?.applicable_percent ?? null,
            ),
        [lineFreights, tdsPreview],
    );

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

    const reviewSections = (): FieldReviewSection[] => [
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
                rows: goodsRows.map((row, index) => [
                    row.label,
                    freightRateTypeLabel(
                        form.data.goods_lines[index].rate_type,
                    ),
                    dash(form.data.goods_lines[index].rate),
                    lineFreights[index] !== null
                        ? formatInr(lineFreights[index])
                        : '—',
                ]),
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
                },
            ],
        },
    ];

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (submitting.current || form.processing || payee === null) {
            return;
        }
        setConfirmOpen(true);
    };

    const submitHire = (redirectToVoucher: boolean) => {
        if (submitting.current || form.processing) {
            return;
        }
        submitting.current = true;
        setConfirmOpen(false);
        form.transform((data) => ({
            ...data,
            redirect_to_voucher: redirectToVoucher,
        }));
        form.post(urls.store, {
            onFinish: () => {
                submitting.current = false;
            },
        });
    };

    const lineError = (index: number, field: 'rate_type' | 'rate') =>
        form.errors[
            `goods_lines.${index}.${field}` as keyof typeof form.errors
        ];

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
                <LrfSection
                    step={1}
                    title="Bilty summary"
                    description="Inherited from the linked Bilty — read only"
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
                        id="lorry-hire-create-form"
                        className="grid w-full gap-3"
                        onSubmit={handleSubmit}
                    >
                        <LrfSection
                            step={2}
                            title="Hire details"
                            description="Transporter rate per goods line — customer freight stays independent"
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
                                        draft.rate_type === 'fixed_amount';
                                    const freight = lineFreights[index];

                                    return (
                                        <div
                                            key={row.id}
                                            className="grid items-start gap-3 rounded-lg border border-border bg-muted/20 p-3 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]"
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
                                                <Label className="text-xs">
                                                    Transporter Freight
                                                </Label>
                                                <p className="flex h-9 items-center text-sm font-medium tabular-nums">
                                                    {freight !== null
                                                        ? formatInr(freight)
                                                        : '—'}
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
                        </LrfSection>

                        <LrfSection
                            step={3}
                            title="Financial summary"
                            description="Transporter freight, applicable TDS and net payable"
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
                                    {
                                        name: 'Net Payable',
                                        value: formatInr(financials.netPayable),
                                        hero: true,
                                    },
                                ]}
                            />
                            {tdsPreview?.explanation ? (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {tdsPreview.explanation}
                                </p>
                            ) : null}
                        </LrfSection>

                        <LrfActions>
                            <Button
                                asChild
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                className="lrf-btn lrf-btn--outline"
                            >
                                <Link href={urls.lr_show}>Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="lrf-btn lrf-btn--brand"
                            >
                                {form.processing
                                    ? 'Creating…'
                                    : 'Create Lorry Hire'}
                            </Button>
                        </LrfActions>
                    </form>
                )}

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
                    sections={reviewSections()}
                    onConfirm={() => submitHire(false)}
                    confirmLabel="Create Lorry Hire"
                    onSecondaryConfirm={
                        permissions.can_create_voucher
                            ? () => submitHire(true)
                            : undefined
                    }
                    secondaryConfirmLabel="Create & Record Voucher"
                />
            </LrfPage>
        </>
    );
}
