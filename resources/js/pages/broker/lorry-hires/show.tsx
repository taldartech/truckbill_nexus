import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type GoodsLine = {
    id: string;
    goods_description: string | null;
    rate_type: string;
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
    };
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
        can_create_adjustment?: boolean;
        can_view_adjustment?: boolean;
        can_manage_delivery?: boolean;
    };
    urls: {
        index: string;
        lr_show: string;
        adjustment_store: string;
        deliveries: string;
    };
};

export default function LorryHireShow({
    lorryHire,
    adjustments,
    adjustmentTypeOptions,
    delivery,
    permissions,
    urls,
}: Props) {
    const [tab, setTab] = useState('overview');

    const adjustmentForm = useForm({
        type: adjustmentTypeOptions[0]?.value ?? 'detention',
        amount: '',
        new_weight: '',
        quantity: '',
        rate: '',
        reason: '',
    });

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
                    <Link
                        href={urls.lr_show}
                        className="text-sm font-medium text-[#2FB36F] hover:underline"
                    >
                        LR {lorryHire.lr_number}
                    </Link>
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
                                                {line.goods_description ?? '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {line.rate_type}
                                            </td>
                                            <td className="px-4 py-3">
                                                {line.loading_weight ?? '—'}
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
                                className="mb-6 rounded-lg border border-border p-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
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
                            <Link
                                href={urls.deliveries}
                                className="mt-4 inline-flex text-sm font-medium text-[#2FB36F] hover:underline"
                            >
                                Open Delivery / Memo workspace
                            </Link>
                        ) : null}
                    </DetailTabPanel>
                </DetailTabs>
            </PageShell>
        </>
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
