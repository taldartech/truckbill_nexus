import { Head, Link, useForm } from '@inertiajs/react';
import { AppIconAction } from '@/components/app-icon-action';
import { ArrowLeft, ExternalLink, FileText, Wallet } from 'lucide-react';
import { useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    FieldReview,
    FormConfirmationMode,
} from '@/components/form-confirmation/types';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type GoodsLine = {
    id: string;
    goods_type_name?: string | null;
    goods_description: string | null;
    goods_label?: string | null;
    rate_type: string;
    rate_type_label?: string | null;
    rate: string | null;
    freight_amount: string | null;
    loading_weight: string | null;
    is_fixed_amount: boolean;
};

type AdjustmentRow = {
    id: string;
    type: string;
    amount: string | null;
    previous_weight: string | null;
    new_weight: string | null;
    quantity: string | null;
    rate: string | null;
    reason: string | null;
    created_at: string | null;
};

type Props = {
    lorryHire: {
        id: string;
        hire_number: string;
        status: string;
        hire_date: string | null;
        lr_number: string | null;
        from_station_name: string | null;
        to_station_name: string | null;
        vehicle_registration: string | null;
        transporter_name: string | null;
        declaration_type: string;
        remarks: string | null;
        freight_total: string | null;
        tds_percent: string | null;
        tds_amount: string | null;
        net_payable: string | null;
        outstanding_amount: string | null;
        goods_lines: GoodsLine[];
        tds_snapshot: {
            applicable_rate: string;
            rule_code: string | null;
            declaration_status: string;
            explanation: string;
        } | null;
        created_at: string | null;
        cancelled_at: string | null;
        cancellation_remark: string | null;
        settle_outstanding: boolean | null;
    };
    cancelPreview: {
        freight_total: string;
        tds_amount: string;
        net_payable: string;
        outstanding_amount: string;
        settle_yes_description: string;
        settle_no_description: string;
        adjustments: Array<{
            id: string;
            type: string;
            amount: string;
            reason: string | null;
        }>;
        vouchers: Array<{
            id: string;
            voucher_number: string;
            amount: string;
            status: string;
        }>;
    } | null;
    adjustments: AdjustmentRow[];
    adjustmentTypeOptions: Array<{ value: string; label: string }>;
    delivery: {
        status: string | null;
        memo_number: string | null;
        pod_number: string | null;
        delivery_date: string | null;
        lr_delivered_at: string | null;
        lr_status: string | null;
    };
    permissions: {
        can_cancel?: boolean;
        can_create_adjustment?: boolean;
        can_view_adjustment?: boolean;
        can_manage_delivery?: boolean;
    };
    urls: {
        index: string;
        lr_show: string;
        adjustment_store: string;
        deliveries: string;
        voucher_create?: string | null;
        cancel: string | null;
    };
};

export default function LorryHireShow({
    lorryHire,
    cancelPreview,
    adjustments,
    adjustmentTypeOptions,
    delivery,
    permissions,
    urls,
}: Props) {
    const [tab, setTab] = useState('overview');

    const adjustmentSubmitAllowed = useRef(false);
    const cancelSubmitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmMode, setConfirmMode] = useState<'adjustment' | 'cancel'>(
        'adjustment',
    );
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const [confirmTitle, setConfirmTitle] = useState('');
    const [confirmDescription, setConfirmDescription] = useState('');
    const [confirmDialogMode, setConfirmDialogMode] =
        useState<FormConfirmationMode>('create');
    const [confirmLabel, setConfirmLabel] = useState<string | undefined>(
        undefined,
    );

    const cancelForm = useForm({
        reason: '',
        settle_outstanding: true as boolean,
    });

    const adjustmentForm = useForm({
        type: adjustmentTypeOptions[0]?.value ?? 'detention',
        amount: '',
        new_weight: '',
        quantity: '',
        rate: '',
        reason: '',
    });

    const adjustmentTypeLabel = (type: string): string =>
        adjustmentTypeOptions.find((option) => option.value === type)?.label ??
        type.replaceAll('_', ' ');

    const adjustmentAmountOrWeightLabel = (type: string): string => {
        if (type === 'unloading_weight') {
            return 'New unloading weight (MT)';
        }
        if (['shortage', 'damage'].includes(type)) {
            return 'Quantity and rate';
        }
        return 'Amount';
    };

    const adjustmentAmountOrWeightValue = (
        data: typeof adjustmentForm.data,
    ): string => {
        if (data.type === 'unloading_weight') {
            return data.new_weight.trim() !== '' ? data.new_weight : '—';
        }
        if (['shortage', 'damage'].includes(data.type)) {
            const qty = data.quantity.trim() !== '' ? data.quantity : '—';
            const rate = data.rate.trim() !== '' ? data.rate : '—';
            return `Qty ${qty} @ ${rate}`;
        }
        return data.amount.trim() !== '' ? data.amount : '—';
    };

    const openAdjustmentConfirmation = () => {
        setConfirmMode('adjustment');
        setConfirmDialogMode('create');
        setConfirmTitle('Save this adjustment?');
        setConfirmDescription(
            'Review adjustment type, amount or weight, and reason before submitting.',
        );
        setConfirmLabel('Confirm save');
        setReviewFields([
            {
                label: 'Type',
                value: adjustmentTypeLabel(adjustmentForm.data.type),
            },
            {
                label: adjustmentAmountOrWeightLabel(adjustmentForm.data.type),
                value: adjustmentAmountOrWeightValue(adjustmentForm.data),
            },
            {
                label: 'Reason',
                value:
                    adjustmentForm.data.reason.trim() !== ''
                        ? adjustmentForm.data.reason
                        : '—',
            },
        ]);
        setConfirmOpen(true);
    };

    const openCancelConfirmation = () => {
        if (!cancelPreview) {
            return;
        }
        setConfirmMode('cancel');
        setConfirmDialogMode('destructive');
        setConfirmTitle('Cancel this Lorry Hire?');
        setConfirmDescription(
            'Review hire number, financial amounts, and cancellation details before confirming. This action cannot be undone.',
        );
        setConfirmLabel('Confirm cancellation');
        setReviewFields([
            {
                label: 'Hire number',
                value: lorryHire.hire_number,
            },
            {
                label: 'Transporter freight',
                value: cancelPreview.freight_total,
            },
            {
                label: 'TDS',
                value: cancelPreview.tds_amount,
            },
            {
                label: 'Net payable',
                value: cancelPreview.net_payable,
            },
            {
                label: 'Outstanding',
                value: cancelPreview.outstanding_amount,
            },
            {
                label: 'Settle outstanding',
                value: cancelForm.data.settle_outstanding
                    ? cancelPreview.settle_yes_description
                    : cancelPreview.settle_no_description,
            },
            {
                label: 'Reason',
                value:
                    cancelForm.data.reason.trim() !== ''
                        ? cancelForm.data.reason
                        : '—',
            },
        ]);
        setConfirmOpen(true);
    };

    const handleConfirmDialog = () => {
        if (confirmMode === 'adjustment') {
            adjustmentSubmitAllowed.current = true;
        } else {
            cancelSubmitAllowed.current = true;
        }
        setConfirmOpen(false);
        window.setTimeout(() => {
            const formId =
                confirmMode === 'adjustment'
                    ? 'lorry-hire-adjustment-form'
                    : 'lorry-hire-cancel-form';
            const formElement = document.getElementById(
                formId,
            ) as HTMLFormElement | null;
            formElement?.requestSubmit();
        }, 0);
    };

    const confirmProcessing =
        confirmMode === 'adjustment'
            ? adjustmentForm.processing
            : cancelForm.processing;

    return (
        <>
            <Head title={`Lorry Hire ${lorryHire.hire_number}`} />
            <PageShell
                title={`Lorry Hire ${lorryHire.hire_number}`}
                actions={
                    <Link
                        href={urls.index}
                        className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        All Lorry Hires
                    </Link>
                }
            >
                <div className="flex flex-wrap items-center gap-3">
                    <WorkspaceStatusBadge status={lorryHire.status} />
                    <AppIconAction
                        icon={FileText}
                        label={`Open LR ${lorryHire.lr_number}`}
                        href={urls.lr_show}
                        variant="info"
                        size="sm"
                    />
                    {urls.voucher_create ? (
                        <AppIconAction
                            icon={Wallet}
                            label="Record voucher"
                            href={urls.voucher_create}
                            variant="default"
                            size="sm"
                        />
                    ) : null}
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Transporter freight"
                        value={lorryHire.freight_total ?? '—'}
                    />
                    <MetricCard
                        label="TDS"
                        value={lorryHire.tds_amount ?? '—'}
                        hint={
                            lorryHire.tds_percent
                                ? `${lorryHire.tds_percent}%`
                                : undefined
                        }
                    />
                    <MetricCard
                        label="Net payable"
                        value={lorryHire.net_payable ?? '—'}
                    />
                    <MetricCard
                        label="Outstanding"
                        value={lorryHire.outstanding_amount ?? '—'}
                    />
                </div>

                <DetailTabs
                    className="mt-6"
                    aria-label="Lorry hire detail sections"
                    tabs={[
                        { id: 'overview', label: 'Overview' },
                        { id: 'freight', label: 'Freight lines' },
                        { id: 'tds', label: 'TDS snapshot' },
                        { id: 'adjustments', label: 'Adjustments' },
                        { id: 'delivery', label: 'Delivery' },
                        ...(permissions.can_cancel &&
                        lorryHire.status !== 'cancelled' &&
                        cancelPreview &&
                        urls.cancel
                            ? [{ id: 'cancel', label: 'Cancel' }]
                            : []),
                    ]}
                    activeId={tab}
                    onChange={setTab}
                >
                    <DetailTabPanel id="overview" activeId={tab}>
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Hire date"
                                value={lorryHire.hire_date}
                            />
                            <Field
                                label="Route"
                                value={`${lorryHire.from_station_name ?? '—'} → ${lorryHire.to_station_name ?? '—'}`}
                            />
                            <Field
                                label="Vehicle"
                                value={lorryHire.vehicle_registration}
                            />
                            <Field
                                label="Transporter"
                                value={lorryHire.transporter_name}
                            />
                            <Field
                                label="Declaration"
                                value={lorryHire.declaration_type}
                            />
                            <Field
                                label="Created"
                                value={lorryHire.created_at}
                            />
                            {lorryHire.status === 'cancelled' ? (
                                <>
                                    <Field
                                        label="Cancelled"
                                        value={lorryHire.cancelled_at}
                                    />
                                    <Field
                                        label="Cancellation reason"
                                        value={lorryHire.cancellation_remark}
                                    />
                                    <Field
                                        label="Settled outstanding"
                                        value={
                                            lorryHire.settle_outstanding ===
                                            null
                                                ? '—'
                                                : lorryHire.settle_outstanding
                                                  ? 'Yes'
                                                  : 'No'
                                        }
                                    />
                                </>
                            ) : null}
                        </dl>
                        {lorryHire.remarks ? (
                            <p className="mt-4 text-sm text-muted-foreground">
                                {lorryHire.remarks}
                            </p>
                        ) : null}
                    </DetailTabPanel>
                    <DetailTabPanel id="freight" activeId={tab}>
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted/40 text-left">
                                    <tr>
                                        <th className="px-4 py-3">
                                            Description
                                        </th>
                                        <th className="px-4 py-3">Rate type</th>
                                        <th className="px-4 py-3">Weight</th>
                                        <th className="px-4 py-3">Freight</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lorryHire.goods_lines.map((line) => (
                                        <tr
                                            key={line.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                {line.goods_label ??
                                                    line.goods_description ??
                                                    line.goods_type_name ??
                                                    '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {line.rate_type_label ??
                                                    line.rate_type.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {line.loading_weight
                                                    ? `${(Number(line.loading_weight) / 1000).toFixed(3)} MT`
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 font-medium">
                                                {line.freight_amount ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </DetailTabPanel>
                    <DetailTabPanel id="tds" activeId={tab}>
                        {lorryHire.tds_snapshot ? (
                            <dl className="grid gap-3 text-sm">
                                <Field
                                    label="Applicable rate"
                                    value={`${lorryHire.tds_snapshot.applicable_rate}%`}
                                />
                                <Field
                                    label="Rule"
                                    value={
                                        lorryHire.tds_snapshot.rule_code ?? '—'
                                    }
                                />
                                <Field
                                    label="Declaration"
                                    value={
                                        lorryHire.tds_snapshot
                                            .declaration_status
                                    }
                                />
                                <div>
                                    <dt className="text-muted-foreground">
                                        Explanation
                                    </dt>
                                    <dd className="mt-1">
                                        {lorryHire.tds_snapshot.explanation}
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No TDS snapshot recorded.
                            </p>
                        )}
                    </DetailTabPanel>
                    <DetailTabPanel id="adjustments" activeId={tab}>
                        {permissions.can_create_adjustment ? (
                            <form
                                id="lorry-hire-adjustment-form"
                                className="mb-6 rounded-lg border border-border p-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    if (!adjustmentSubmitAllowed.current) {
                                        openAdjustmentConfirmation();
                                        return;
                                    }
                                    adjustmentSubmitAllowed.current = false;
                                    adjustmentForm.post(urls.adjustment_store, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <h3 className="text-sm font-semibold">
                                    New adjustment
                                </h3>
                                <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label htmlFor="adj_type">Type</Label>
                                        <select
                                            id="adj_type"
                                            className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            value={adjustmentForm.data.type}
                                            onChange={(e) =>
                                                adjustmentForm.setData(
                                                    'type',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {adjustmentTypeOptions.map(
                                                (opt) => (
                                                    <option
                                                        key={opt.value}
                                                        value={opt.value}
                                                    >
                                                        {opt.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    {[
                                        'detention',
                                        'deviation',
                                        'misc_expense',
                                    ].includes(adjustmentForm.data.type) ? (
                                        <div>
                                            <Label htmlFor="adj_amount">
                                                Amount
                                            </Label>
                                            <Input
                                                id="adj_amount"
                                                type="number"
                                                step="0.01"
                                                value={
                                                    adjustmentForm.data.amount
                                                }
                                                onChange={(e) =>
                                                    adjustmentForm.setData(
                                                        'amount',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </div>
                                    ) : null}
                                    {adjustmentForm.data.type ===
                                    'unloading_weight' ? (
                                        <div>
                                            <Label htmlFor="adj_weight">
                                                New unloading weight (MT)
                                            </Label>
                                            <Input
                                                id="adj_weight"
                                                type="number"
                                                step="0.001"
                                                value={
                                                    adjustmentForm.data
                                                        .new_weight
                                                }
                                                onChange={(e) =>
                                                    adjustmentForm.setData(
                                                        'new_weight',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </div>
                                    ) : null}
                                    {['shortage', 'damage'].includes(
                                        adjustmentForm.data.type,
                                    ) ? (
                                        <>
                                            <div>
                                                <Label htmlFor="adj_qty">
                                                    Quantity
                                                </Label>
                                                <Input
                                                    id="adj_qty"
                                                    type="number"
                                                    step="0.001"
                                                    value={
                                                        adjustmentForm.data
                                                            .quantity
                                                    }
                                                    onChange={(e) =>
                                                        adjustmentForm.setData(
                                                            'quantity',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="adj_rate">
                                                    Rate
                                                </Label>
                                                <Input
                                                    id="adj_rate"
                                                    type="number"
                                                    step="0.0001"
                                                    value={
                                                        adjustmentForm.data.rate
                                                    }
                                                    onChange={(e) =>
                                                        adjustmentForm.setData(
                                                            'rate',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                        </>
                                    ) : null}
                                    <div className="sm:col-span-2">
                                        <Label htmlFor="adj_reason">
                                            Reason
                                        </Label>
                                        <Input
                                            id="adj_reason"
                                            value={adjustmentForm.data.reason}
                                            onChange={(e) =>
                                                adjustmentForm.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <Button
                                    type="submit"
                                    className="mt-4 bg-[#2FB36F] text-white hover:bg-[#279960]"
                                    disabled={adjustmentForm.processing}
                                >
                                    Save adjustment
                                </Button>
                            </form>
                        ) : null}
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted/40 text-left">
                                    <tr>
                                        <th className="px-4 py-3">Type</th>
                                        <th className="px-4 py-3">Amount</th>
                                        <th className="px-4 py-3">Weights</th>
                                        <th className="px-4 py-3">When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {adjustments.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                {row.type}
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.amount ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">
                                                {row.previous_weight
                                                    ? `${row.previous_weight} → ${row.new_weight}`
                                                    : row.quantity
                                                      ? `Qty ${row.quantity} @ ${row.rate}`
                                                      : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.created_at ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {adjustments.length === 0 ? (
                                <p className="px-4 py-3 text-sm text-muted-foreground">
                                    No adjustments yet.
                                </p>
                            ) : null}
                        </div>
                    </DetailTabPanel>
                    <DetailTabPanel id="delivery" activeId={tab}>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <Field
                                label="LR status"
                                value={delivery.lr_status}
                            />
                            <Field
                                label="LR delivered"
                                value={delivery.lr_delivered_at}
                            />
                            <Field label="Memo" value={delivery.memo_number} />
                            <Field label="POD" value={delivery.pod_number} />
                            <Field
                                label="Delivery record"
                                value={delivery.delivery_date}
                            />
                            <Field
                                label="Delivery status"
                                value={delivery.status}
                            />
                        </dl>
                        {permissions.can_manage_delivery ? (
                            <div className="mt-3">
                                <AppIconAction
                                    icon={ExternalLink}
                                    label="Open Delivery / Memo workspace"
                                    href={urls.deliveries}
                                    variant="secondary"
                                    size="sm"
                                />
                            </div>
                        ) : null}
                    </DetailTabPanel>
                    <DetailTabPanel id="cancel" activeId={tab}>
                        {cancelPreview && urls.cancel ? (
                            <form
                                id="lorry-hire-cancel-form"
                                className="max-w-2xl space-y-4 rounded-lg border border-destructive/30 bg-destructive/5 p-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    const cancelUrl = urls.cancel;
                                    if (!cancelUrl) {
                                        return;
                                    }
                                    if (!cancelSubmitAllowed.current) {
                                        openCancelConfirmation();
                                        return;
                                    }
                                    cancelSubmitAllowed.current = false;
                                    cancelForm.post(cancelUrl, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <h3 className="text-sm font-semibold text-destructive">
                                    Cancel Lorry Hire
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Review amounts before confirming. This
                                    action cannot be undone.
                                </p>
                                <dl className="grid gap-2 rounded-md border border-border bg-background p-3 text-sm sm:grid-cols-2">
                                    <PreviewField
                                        label="Transporter freight"
                                        value={lorryHire.freight_total}
                                    />
                                    <PreviewField
                                        label="TDS"
                                        value={lorryHire.tds_amount}
                                    />
                                    <PreviewField
                                        label="Net payable"
                                        value={lorryHire.net_payable}
                                    />
                                    <PreviewField
                                        label="Outstanding"
                                        value={lorryHire.outstanding_amount}
                                    />
                                    <PreviewField
                                        label="Adjustments"
                                        value={String(
                                            cancelPreview.adjustments.length,
                                        )}
                                    />
                                    <PreviewField
                                        label="Vouchers"
                                        value={String(
                                            cancelPreview.vouchers.length,
                                        )}
                                    />
                                </dl>
                                <fieldset className="space-y-2">
                                    <legend className="text-sm font-medium">
                                        Settle outstanding to transporter book?
                                    </legend>
                                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="settle_outstanding"
                                            className="mt-1"
                                            checked={
                                                cancelForm.data
                                                    .settle_outstanding === true
                                            }
                                            onChange={() =>
                                                cancelForm.setData(
                                                    'settle_outstanding',
                                                    true,
                                                )
                                            }
                                        />
                                        <span>
                                            {
                                                cancelPreview.settle_yes_description
                                            }
                                        </span>
                                    </label>
                                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="settle_outstanding"
                                            className="mt-1"
                                            checked={
                                                cancelForm.data
                                                    .settle_outstanding ===
                                                false
                                            }
                                            onChange={() =>
                                                cancelForm.setData(
                                                    'settle_outstanding',
                                                    false,
                                                )
                                            }
                                        />
                                        <span>
                                            {
                                                cancelPreview.settle_no_description
                                            }
                                        </span>
                                    </label>
                                </fieldset>
                                <div>
                                    <Label htmlFor="cancel_reason">
                                        Reason (required)
                                    </Label>
                                    <Input
                                        id="cancel_reason"
                                        className="mt-1"
                                        value={cancelForm.data.reason}
                                        onChange={(e) =>
                                            cancelForm.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                        required
                                        maxLength={2000}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={cancelForm.processing}
                                >
                                    Confirm cancellation
                                </Button>
                            </form>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Cancellation is not available for this Lorry
                                Hire.
                            </p>
                        )}
                    </DetailTabPanel>
                </DetailTabs>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode={confirmDialogMode}
                    title={confirmTitle}
                    description={confirmDescription}
                    fields={reviewFields}
                    processing={confirmProcessing}
                    confirmLabel={confirmLabel}
                    onConfirm={handleConfirmDialog}
                />
            </PageShell>
        </>
    );
}

function PreviewField({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value ?? '—'}</dd>
        </div>
    );
}

function Field({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium">{value ?? '—'}</dd>
        </div>
    );
}
