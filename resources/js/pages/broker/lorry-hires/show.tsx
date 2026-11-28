import { Head, useForm } from '@inertiajs/react';
import { AppIconAction } from '@/components/app-icon-action';
import { VoucherPaymentDialog } from '@/components/broker/voucher-payment-dialog';
import {
    FbDetailCard,
    FbDetailLayout,
    FbDetailPage,
    FbFinancialSummary,
    FbInfoGrid,
    FbInfoItem,
    FbRouteCard,
} from '@/components/fb-detail';
import {
    ArrowLeft,
    ExternalLink,
    FileText,
    Pencil,
    Printer,
    Wallet,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type {
    FieldReview,
    FormConfirmationMode,
} from '@/components/form-confirmation/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

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

type RevisionRow = {
    id: string;
    created_at: string | null;
    created_by: string | null;
    remark: string;
    freight_before: string | null;
    freight_after: string | null;
    tds_before: string | null;
    tds_after: string | null;
    net_payable_before: string | null;
    net_payable_after: string | null;
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
        lr_date?: string | null;
        from_station_name: string | null;
        to_station_name: string | null;
        billing_customer_name?: string | null;
        consignor_name?: string | null;
        consignee_name?: string | null;
        vehicle_registration: string | null;
        transporter_name: string | null;
        customer_freight_total?: string | null;
        remarks: string | null;
        freight_total: string | null;
        tds_percent: string | null;
        tds_amount: string | null;
        net_payable: string | null;
        outstanding_amount: string | null;
        balance_due?: string | null;
        negative_adjusted_amount?: string | null;
        remaining_payable?: string | null;
        reserved_voucher_amount?: string | null;
        goods_lines: GoodsLine[];
        custom_amounts: Array<{
            id: string;
            description: string;
            amount: string | null;
        }>;
        custom_amounts_total?: string | null;
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
    revisions?: RevisionRow[];
    adjustmentTypeOptions: Array<{ value: string; label: string }>;
    fixed_adjustments: {
        detention: string;
        deviation: string;
        misc_expense: string;
        unloading_weight: string;
        shortage: string;
        damage: string;
    };
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
        can_pay?: boolean;
        can_edit?: boolean;
    };
    urls: {
        index: string;
        lr_show: string;
        adjustment_store: string;
        deliveries: string;
        payment_context: string | null;
        voucher_store: string | null;
        cancel: string | null;
        edit?: string | null;
        print?: string;
        memo_print?: string | null;
    };
};

export default function LorryHireShow({
    lorryHire,
    cancelPreview,
    adjustments,
    revisions = [],
    adjustmentTypeOptions: _adjustmentTypeOptions,
    fixed_adjustments,
    delivery,
    permissions,
    urls,
}: Props) {
    const [paymentOpen, setPaymentOpen] = useState(
        () =>
            Boolean(permissions.can_pay) &&
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('pay') === '1',
    );
    const closePayment = (open: boolean) => {
        setPaymentOpen(open);
        if (!open && window.location.search.includes('pay=1')) {
            window.history.replaceState(
                window.history.state,
                '',
                window.location.pathname,
            );
        }
    };
    const cancelSubmitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
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

    const openCancelConfirmation = () => {
        if (!cancelPreview) {
            return;
        }
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
        cancelSubmitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => {
            const formElement = document.getElementById(
                'lorry-hire-cancel-form',
            ) as HTMLFormElement | null;
            formElement?.requestSubmit();
        }, 0);
    };

    const confirmProcessing = cancelForm.processing;

    const goodsLabel = useMemo(() => {
        const first = lorryHire.goods_lines[0];
        if (!first) {
            return '—';
        }
        if (lorryHire.goods_lines.length > 1) {
            return `${first.goods_label ?? first.goods_type_name ?? 'Goods'} +${lorryHire.goods_lines.length - 1}`;
        }
        return first.goods_label ?? first.goods_type_name ?? '—';
    }, [lorryHire.goods_lines]);

    const totalWeightMt = useMemo(() => {
        const kg = lorryHire.goods_lines.reduce(
            (sum, line) => sum + Number(line.loading_weight ?? 0),
            0,
        );
        return kg > 0 ? `${(kg / 1000).toFixed(3)} MT` : '—';
    }, [lorryHire.goods_lines]);

    const balanceDueNumber = Number(
        lorryHire.balance_due ?? lorryHire.outstanding_amount ?? NaN,
    );
    const balanceDue =
        Number.isFinite(balanceDueNumber) && balanceDueNumber > 0;
    const cleared = Number.isFinite(balanceDueNumber) && balanceDueNumber <= 0;

    return (
        <>
            <Head title={`Lorry Hire ${lorryHire.hire_number}`} />
            <FbDetailPage className="freight-booking-show">
                {lorryHire.status === 'cancelled' ? (
                    <div
                        className="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
                        role="alert"
                    >
                        <strong>Cancelled good hire</strong>
                        <div className="text-xs">
                            {lorryHire.cancellation_remark ??
                                'This booking cannot be modified.'}
                        </div>
                    </div>
                ) : null}

                <FbDetailLayout
                    main={
                        <>
                            <FbDetailCard
                                title={`Good Hire Information (${lorryHire.hire_number})`}
                                badges={
                                    <>
                                        <span
                                            className={cn(
                                                'rounded px-1.5 py-0.5 text-[10px] font-bold uppercase',
                                                lorryHire.status === 'cancelled'
                                                    ? 'bg-red-100 text-red-700'
                                                    : 'bg-emerald-100 text-emerald-800',
                                            )}
                                        >
                                            {lorryHire.status}
                                        </span>
                                    </>
                                }
                                actions={
                                    <>
                                        <AppIconAction
                                            icon={ArrowLeft}
                                            label="Back to list"
                                            href={urls.index}
                                            variant="outline"
                                            size="sm"
                                        />
                                        <AppIconAction
                                            icon={FileText}
                                            label="Open bilty"
                                            href={urls.lr_show}
                                            variant="info"
                                            size="sm"
                                        />
                                        {permissions.can_edit && urls.edit ? (
                                            <AppIconAction
                                                icon={Pencil}
                                                label="Edit Lorry Hire"
                                                href={urls.edit}
                                                variant="outline"
                                                size="sm"
                                            />
                                        ) : null}
                                        {urls.print ? (
                                            <AppIconAction
                                                icon={Printer}
                                                label="Print good hire"
                                                href={urls.print}
                                                external
                                                variant="outline"
                                                size="sm"
                                            />
                                        ) : null}
                                        {urls.memo_print ? (
                                            <AppIconAction
                                                icon={Printer}
                                                label="Print memo"
                                                href={urls.memo_print}
                                                external
                                                variant="outline"
                                                size="sm"
                                            />
                                        ) : null}
                                        {permissions.can_pay ? (
                                            <AppIconAction
                                                icon={Wallet}
                                                label="Voucher Payment"
                                                onClick={() =>
                                                    setPaymentOpen(true)
                                                }
                                                variant="default"
                                                size="sm"
                                            />
                                        ) : null}
                                    </>
                                }
                            >
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="LR Number"
                                        value={lorryHire.lr_number}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="GR Date"
                                        value={
                                            lorryHire.lr_date ??
                                            lorryHire.hire_date
                                        }
                                    />
                                    <FbInfoItem
                                        label="Goods"
                                        value={goodsLabel}
                                    />
                                    <FbInfoItem
                                        label="Weight"
                                        value={totalWeightMt}
                                    />
                                    <FbInfoItem
                                        label="Truck"
                                        value={lorryHire.vehicle_registration}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="Payee"
                                        value={lorryHire.transporter_name}
                                        sub={
                                            lorryHire.tds_percent
                                                ? `TDS ${lorryHire.tds_percent}%`
                                                : undefined
                                        }
                                    />
                                    <FbInfoItem
                                        label="Customer"
                                        value={lorryHire.billing_customer_name}
                                    />
                                    <FbInfoItem
                                        label="Delivery"
                                        value={
                                            delivery.status ??
                                            delivery.lr_status ??
                                            'Pending'
                                        }
                                    />
                                    <FbInfoItem
                                        label="Consignor"
                                        value={lorryHire.consignor_name}
                                    />
                                    <FbInfoItem
                                        label="Consignee"
                                        value={lorryHire.consignee_name}
                                    />
                                    <FbInfoItem
                                        label="Memo"
                                        value={delivery.memo_number}
                                    />
                                    <FbInfoItem
                                        label="POD"
                                        value={delivery.pod_number}
                                    />
                                    {lorryHire.remarks ? (
                                        <FbInfoItem
                                            label="Remark"
                                            value={lorryHire.remarks}
                                            full
                                        />
                                    ) : null}
                                </FbInfoGrid>
                            </FbDetailCard>

                            <FbRouteCard
                                from={lorryHire.from_station_name ?? '—'}
                                to={lorryHire.to_station_name ?? '—'}
                                customer={
                                    lorryHire.billing_customer_name ?? '—'
                                }
                                payee={lorryHire.transporter_name ?? '—'}
                                lrAmount={lorryHire.customer_freight_total}
                            />

                            <FbDetailCard title="Hire Charges">
                                <div className="overflow-x-auto px-4 py-3">
                                    <table className="min-w-full text-sm">
                                        <thead>
                                            <tr className="text-left text-xs text-slate-500">
                                                <th className="px-2 py-2">
                                                    Description
                                                </th>
                                                <th className="px-2 py-2">
                                                    Rate type
                                                </th>
                                                <th className="px-2 py-2">
                                                    Rate
                                                </th>
                                                <th className="px-2 py-2">
                                                    Weight
                                                </th>
                                                <th className="px-2 py-2">
                                                    Transporter Freight
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {lorryHire.goods_lines.map(
                                                (line) => (
                                                    <tr
                                                        key={line.id}
                                                        className="border-t border-slate-100"
                                                    >
                                                        <td className="px-2 py-2">
                                                            {line.goods_label ??
                                                                line.goods_description ??
                                                                line.goods_type_name ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            {line.rate_type_label ??
                                                                line.rate_type}
                                                        </td>
                                                        <td className="px-2 py-2 tabular-nums">
                                                            {line.rate ?? '—'}
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            {line.loading_weight
                                                                ? `${(Number(line.loading_weight) / 1000).toFixed(3)} MT`
                                                                : '—'}
                                                        </td>
                                                        <td className="px-2 py-2 font-medium tabular-nums">
                                                            {line.freight_amount ??
                                                                '—'}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                {lorryHire.custom_amounts.length > 0 ? (
                                    <div className="border-t border-slate-100 px-4 py-3">
                                        <p className="mb-2 text-xs font-medium text-slate-600">
                                            Custom Amounts
                                        </p>
                                        <table className="min-w-full text-sm">
                                            <thead>
                                                <tr className="text-left text-xs text-slate-500">
                                                    <th className="px-2 py-1">
                                                        Particulars
                                                    </th>
                                                    <th className="px-2 py-1">
                                                        Amount (+/−)
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {lorryHire.custom_amounts.map(
                                                    (row) => (
                                                        <tr
                                                            key={row.id}
                                                            className="border-t border-slate-100"
                                                        >
                                                            <td className="px-2 py-1">
                                                                {
                                                                    row.description
                                                                }
                                                            </td>
                                                            <td className="px-2 py-1 tabular-nums">
                                                                {row.amount ??
                                                                    '—'}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                        {lorryHire.custom_amounts_total ? (
                                            <p className="mt-2 text-xs text-slate-600">
                                                Custom Amounts total:{' '}
                                                {lorryHire.custom_amounts_total}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                                {lorryHire.tds_snapshot ? (
                                    <div className="border-t border-slate-100 px-4 py-3 text-xs text-slate-600">
                                        TDS{' '}
                                        {lorryHire.tds_snapshot.applicable_rate}
                                        % · {lorryHire.tds_snapshot.explanation}
                                    </div>
                                ) : null}
                            </FbDetailCard>

                            {revisions.length > 0 ? (
                                <FbDetailCard title="Edit History">
                                    <div className="overflow-x-auto px-4 py-3">
                                        <table className="min-w-full text-sm">
                                            <thead>
                                                <tr className="text-left text-xs text-slate-500">
                                                    <th className="px-2 py-2">
                                                        When
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        By
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        Freight
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        TDS
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        Net Payable
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        Remark
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {revisions.map((revision) => (
                                                    <tr
                                                        key={revision.id}
                                                        className="border-t border-slate-100 align-top"
                                                    >
                                                        <td className="px-2 py-2 whitespace-nowrap">
                                                            {revision.created_at ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            {revision.created_by ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-2 py-2 tabular-nums">
                                                            {revision.freight_before ??
                                                                '—'}{' '}
                                                            →{' '}
                                                            {revision.freight_after ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-2 py-2 tabular-nums">
                                                            {revision.tds_before ??
                                                                '—'}{' '}
                                                            →{' '}
                                                            {revision.tds_after ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-2 py-2 tabular-nums">
                                                            {revision.net_payable_before ??
                                                                '—'}{' '}
                                                            →{' '}
                                                            {revision.net_payable_after ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-2 py-2 break-words">
                                                            {revision.remark}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </FbDetailCard>
                            ) : null}

                            <FbDetailCard title="Freight Adjustments">
                                {permissions.can_edit && urls.edit ? (
                                    <p className="border-b border-slate-100 px-4 py-3 text-sm text-muted-foreground">
                                        Detention, deviation, misc. expense,
                                        unloading weight, shortage and damage
                                        are edited on{' '}
                                        <a
                                            href={urls.edit}
                                            className="font-medium text-[#2FB36F] underline-offset-2 hover:underline"
                                        >
                                            Lorry Hire Edit
                                        </a>
                                        .
                                    </p>
                                ) : null}
                                <div
                                    className="lrf-grid lrf-grid--6 border-b border-slate-100 px-4 py-3"
                                    data-testid="lorry-hire-fixed-adjustments-readonly"
                                >
                                    <FbInfoItem
                                        label="Detention"
                                        value={
                                            fixed_adjustments.detention || '—'
                                        }
                                    />
                                    <FbInfoItem
                                        label="Deviation"
                                        value={
                                            fixed_adjustments.deviation || '—'
                                        }
                                    />
                                    <FbInfoItem
                                        label="Misc. Expense"
                                        value={
                                            fixed_adjustments.misc_expense ||
                                            '—'
                                        }
                                    />
                                    <FbInfoItem
                                        label="Unloading Wt."
                                        value={
                                            fixed_adjustments.unloading_weight ||
                                            '—'
                                        }
                                    />
                                    <FbInfoItem
                                        label="Shortage Amt."
                                        value={
                                            fixed_adjustments.shortage || '—'
                                        }
                                    />
                                    <FbInfoItem
                                        label="Damage Amt."
                                        value={fixed_adjustments.damage || '—'}
                                    />
                                </div>
                                {permissions.can_view_adjustment ? (
                                    <div className="overflow-x-auto px-4 pb-3">
                                        <table className="min-w-full text-sm">
                                            <thead>
                                                <tr className="text-left text-xs text-slate-500">
                                                    <th className="px-2 py-2">
                                                        Type
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        Amount
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        Weights
                                                    </th>
                                                    <th className="px-2 py-2">
                                                        When
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {adjustments.map((row) => (
                                                    <tr
                                                        key={row.id}
                                                        className="border-t border-slate-100"
                                                    >
                                                        <td className="px-2 py-2">
                                                            {row.type}
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            {row.amount ?? '—'}
                                                        </td>
                                                        <td className="px-2 py-2 text-xs text-muted-foreground">
                                                            {row.previous_weight
                                                                ? `${row.previous_weight} → ${row.new_weight}`
                                                                : row.quantity
                                                                  ? `Qty ${row.quantity} @ ${row.rate}`
                                                                  : '—'}
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            {row.created_at ??
                                                                '—'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                        {adjustments.length === 0 ? (
                                            <p className="px-2 py-3 text-sm text-muted-foreground">
                                                No adjustments yet.
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </FbDetailCard>

                            <FbDetailCard title="Delivery / Memo / POD">
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="LR status"
                                        value={delivery.lr_status}
                                    />
                                    <FbInfoItem
                                        label="Delivered"
                                        value={delivery.lr_delivered_at}
                                    />
                                    <FbInfoItem
                                        label="Memo"
                                        value={delivery.memo_number}
                                    />
                                    <FbInfoItem
                                        label="POD"
                                        value={delivery.pod_number}
                                    />
                                </FbInfoGrid>
                                {permissions.can_manage_delivery ? (
                                    <div className="border-t border-slate-100 px-4 py-3">
                                        <AppIconAction
                                            icon={ExternalLink}
                                            label="Open Delivery / Memo workspace"
                                            href={urls.deliveries}
                                            variant="secondary"
                                            size="sm"
                                        />
                                    </div>
                                ) : null}
                            </FbDetailCard>

                            {permissions.can_cancel &&
                            lorryHire.status !== 'cancelled' &&
                            cancelPreview &&
                            urls.cancel ? (
                                <FbDetailCard title="Cancel Lorry Hire">
                                    <form
                                        id="lorry-hire-cancel-form"
                                        className="space-y-4 px-4 py-3"
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
                                        <p className="text-sm text-muted-foreground">
                                            Review amounts before confirming.
                                            This action cannot be undone.
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
                                                label="Balance Due"
                                                value={
                                                    lorryHire.balance_due ??
                                                    lorryHire.outstanding_amount
                                                }
                                            />
                                            <PreviewField
                                                label="Negative Adjusted Amount"
                                                value={
                                                    lorryHire.negative_adjusted_amount ??
                                                    '0.00'
                                                }
                                            />
                                        </dl>
                                        <fieldset className="space-y-2">
                                            <legend className="text-sm font-medium">
                                                Settle outstanding to
                                                transporter book?
                                            </legend>
                                            <label className="flex cursor-pointer items-start gap-2 text-sm">
                                                <input
                                                    type="radio"
                                                    name="settle_outstanding"
                                                    className="mt-1"
                                                    checked={
                                                        cancelForm.data
                                                            .settle_outstanding ===
                                                        true
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
                                </FbDetailCard>
                            ) : null}
                        </>
                    }
                    side={
                        <>
                            <FbFinancialSummary
                                balanceDue={balanceDue}
                                cleared={cleared}
                                trAmount={lorryHire.freight_total ?? '—'}
                                deductions={lorryHire.tds_amount ?? '—'}
                                balance={
                                    lorryHire.balance_due ??
                                    lorryHire.outstanding_amount ??
                                    '—'
                                }
                                rows={[
                                    {
                                        label: 'TR Freight',
                                        value: lorryHire.freight_total ?? '—',
                                    },
                                    {
                                        label: 'TDS',
                                        value: lorryHire.tds_amount ?? '—',
                                    },
                                    {
                                        label: 'Net payable',
                                        value: lorryHire.net_payable ?? '—',
                                    },
                                    {
                                        label: 'Balance Due',
                                        value:
                                            lorryHire.balance_due ??
                                            lorryHire.outstanding_amount ??
                                            '—',
                                    },
                                    {
                                        label: 'Negative Adjusted Amount',
                                        value:
                                            lorryHire.negative_adjusted_amount ??
                                            '0.00',
                                    },
                                    {
                                        label: 'In-progress / reserved',
                                        value:
                                            lorryHire.reserved_voucher_amount ??
                                            '0.00',
                                    },
                                    {
                                        label: 'Remaining payable',
                                        value:
                                            lorryHire.remaining_payable ??
                                            lorryHire.outstanding_amount ??
                                            '—',
                                    },
                                    {
                                        label: 'Customer freight',
                                        value:
                                            lorryHire.customer_freight_total ??
                                            '—',
                                    },
                                ]}
                            />
                            <FbDetailCard title="Record Info">
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Created"
                                        value={lorryHire.created_at}
                                    />
                                    <FbInfoItem
                                        label="Hire date"
                                        value={lorryHire.hire_date}
                                    />
                                    <FbInfoItem
                                        label="Hire #"
                                        value={lorryHire.hire_number}
                                    />
                                    <FbInfoItem
                                        label="Status"
                                        value={lorryHire.status}
                                    />
                                </FbInfoGrid>
                            </FbDetailCard>
                        </>
                    }
                />

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

                {permissions.can_pay &&
                urls.payment_context &&
                urls.voucher_store ? (
                    <VoucherPaymentDialog
                        open={paymentOpen}
                        onOpenChange={closePayment}
                        hireIds={[lorryHire.id]}
                        urls={{
                            payment_context: urls.payment_context,
                            voucher_store: urls.voucher_store,
                        }}
                    />
                ) : null}
            </FbDetailPage>
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
