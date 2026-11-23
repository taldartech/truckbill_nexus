import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import { PageShell } from '@/components/page-shell';
import { MetricCard } from '@/components/ui/metric-card';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type GoodsLine = {
    id: string;
    goods_description: string | null;
    rate_type: string;
    rate: string | null;
    freight_amount: string | null;
    loading_weight: string | null;
    is_fixed_amount: boolean;
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
    urls: {
        index: string;
        lr_show: string;
    };
};

export default function LorryHireShow({ lorryHire, urls }: Props) {
    const [tab, setTab] = useState('overview');

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
