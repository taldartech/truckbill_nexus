import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Pencil } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { DetailTabPanel, DetailTabs } from '@/components/detail-tabs';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { FormSection } from '@/components/form-section';
import { MetricCard } from '@/components/ui/metric-card';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDate } from '@/lib/dates';
import { workflowContextLabel } from '@/lib/workflow-context-label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type LorryReceipt = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    status: string;
    from_text: string | null;
    to_text: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    consignor_name: string | null;
    consignee_name: string | null;
    goods_value: string | null;
    freight_total: string;
    additional_charges_total: string;
    cancellation_remark: string | null;
    goods: Array<{
        id: string;
        goods_description: string | null;
        package_count: string | null;
        actual_weight: string | null;
        charged_weight: string | null;
        freight_rate_type: string;
        freight_rate: string;
        freight_amount: string;
        goods_value: string | null;
    }>;
    eway_bills: Array<{
        id: string;
        ewb_number: string;
        ewb_date: string | null;
        valid_until: string | null;
    }>;
    additional_charges: Array<{
        id: string;
        description: string;
        amount: string;
    }>;
};

type Props = {
    lorryReceipt: LorryReceipt;
    permissions: {
        can_edit: boolean;
        can_cancel: boolean;
        can_delete: boolean;
        can_create_trip?: boolean;
        can_create_lorry_hire?: boolean;
    };
    linkedTrip?: {
        id: string;
        status: string;
        show_url: string;
    } | null;
    linkedLorryHire?: {
        id: string;
        hire_number: string;
        show_url: string;
    } | null;
    urls: {
        index: string;
        edit: string;
        cancel: string;
        destroy: string;
        print: string;
        create_trip?: string;
        create_lorry_hire?: string;
        documents?: string;
    };
    documentDrawer?: Omit<DocumentDrawerProps, 'open' | 'onOpenChange'> | null;
};

export default function LorryReceiptShow({
    lorryReceipt,
    permissions,
    linkedTrip = null,
    linkedLorryHire = null,
    urls,
    documentDrawer = null,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [tab, setTab] = useState('overview');
    const [docsOpen, setDocsOpen] = useState(false);
    const isPending = lorryReceipt.status === 'pending';

    return (
        <>
            <Head title={`Bilty ${lorryReceipt.lr_number}`} />
            <PageShell
                title={`Bilty ${lorryReceipt.lr_number}`}
                description={workflowContextLabel(
                    lorryReceipt.workflow_context,
                )}
                actions={
                    <>
                        <AppIconAction
                            icon={ArrowLeft}
                            label="Back to list"
                            href={urls.index}
                            variant="outline"
                        />
                        <AppIconAction
                            icon={Pencil}
                            label="Edit LR"
                            href={urls.edit}
                            variant="warning"
                            visible={permissions.can_edit && isPending}
                        />
                    </>
                }
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard
                        label="Status"
                        value={
                            <WorkspaceStatusBadge
                                status={lorryReceipt.status}
                            />
                        }
                        tone="blue"
                    />
                    <MetricCard
                        label="Route"
                        value={`${lorryReceipt.from_station_name || lorryReceipt.from_text || '—'} → ${lorryReceipt.to_station_name || lorryReceipt.to_text || '—'}`}
                        tone="neutral"
                    />
                    <MetricCard
                        label="Freight"
                        value={`₹${lorryReceipt.freight_total}`}
                        tone="green"
                    />
                    <MetricCard
                        label="Goods lines"
                        value={lorryReceipt.goods.length}
                        tone="amber"
                    />
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <DetailTabs
                    aria-label="Lorry receipt sections"
                    tabs={[
                        { id: 'overview', label: 'Overview' },
                        {
                            id: 'goods',
                            label: 'Goods',
                            count: lorryReceipt.goods.length,
                        },
                        {
                            id: 'documents',
                            label: 'E-Way / Charges',
                            count:
                                lorryReceipt.eway_bills.length +
                                lorryReceipt.additional_charges.length,
                        },
                        {
                            id: 'files',
                            label: 'Files / POD',
                            count: documentDrawer?.attachments.length ?? 0,
                        },
                        { id: 'activity', label: 'Actions' },
                    ]}
                    activeId={tab}
                    onChange={setTab}
                >
                    <DetailTabPanel id="activity" activeId={tab}>
                        <div className="flex flex-wrap gap-2">
                            {permissions.can_edit && isPending ? (
                                <Button asChild variant="secondary">
                                    <Link href={urls.edit}>Edit</Link>
                                </Button>
                            ) : null}
                            {permissions.can_create_trip && urls.create_trip ? (
                                <Button
                                    asChild
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    <Link href={urls.create_trip}>
                                        Manage Expenses
                                    </Link>
                                </Button>
                            ) : null}
                            {permissions.can_create_lorry_hire &&
                            urls.create_lorry_hire ? (
                                <Button
                                    asChild
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    <Link href={urls.create_lorry_hire}>
                                        Manage Lorry Hire
                                    </Link>
                                </Button>
                            ) : null}
                            {linkedLorryHire ? (
                                <Button asChild variant="secondary">
                                    <Link href={linkedLorryHire.show_url}>
                                        Manage Lorry Hire{' '}
                                        {linkedLorryHire.hire_number}
                                    </Link>
                                </Button>
                            ) : null}
                            {linkedTrip ? (
                                <Button asChild variant="secondary">
                                    <Link href={linkedTrip.show_url}>
                                        Manage Expenses
                                    </Link>
                                </Button>
                            ) : null}
                            <Button asChild variant="secondary">
                                <Link href={urls.print}>Print</Link>
                            </Button>
                            {permissions.can_delete && isPending ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() =>
                                        setConfirmDelete((value) => !value)
                                    }
                                >
                                    Delete
                                </Button>
                            ) : null}
                        </div>

                        {confirmDelete ? (
                            <Form
                                action={urls.destroy}
                                method="delete"
                                className="rounded-lg border border-destructive/40 bg-destructive/5 p-4"
                            >
                                {({ processing }) => (
                                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                        <p className="text-sm">
                                            Soft-delete this pending LR? This
                                            cannot be undone from the UI.
                                        </p>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                onClick={() =>
                                                    setConfirmDelete(false)
                                                }
                                            >
                                                Keep
                                            </Button>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Confirm delete
                                            </Button>
                                        </div>
                                        {errors?.delete ? (
                                            <p className="text-sm text-destructive">
                                                {errors.delete}
                                            </p>
                                        ) : null}
                                    </div>
                                )}
                            </Form>
                        ) : null}
                    </DetailTabPanel>

                    <DetailTabPanel id="overview" activeId={tab}>
                        <FormSection title="Consignment overview" columns={3}>
                            <section className="contents">
                                <Detail
                                    label="Status"
                                    value={lorryReceipt.status}
                                />
                                <Detail
                                    label="Service"
                                    value={workflowContextLabel(
                                        lorryReceipt.workflow_context,
                                    )}
                                />
                                <Detail
                                    label="Date"
                                    value={formatDisplayDate(
                                        lorryReceipt.lr_date,
                                    )}
                                />
                                <Detail
                                    label="Loading Station"
                                    value={
                                        lorryReceipt.from_station_name ||
                                        lorryReceipt.from_text ||
                                        '—'
                                    }
                                />
                                <Detail
                                    label="Unloading Station"
                                    value={
                                        lorryReceipt.to_station_name ||
                                        lorryReceipt.to_text ||
                                        '—'
                                    }
                                />
                                <Detail
                                    label="Consignor"
                                    value={lorryReceipt.consignor_name || '—'}
                                />
                                <Detail
                                    label="Consignee"
                                    value={lorryReceipt.consignee_name || '—'}
                                />
                                <Detail
                                    label="Freight total"
                                    value={lorryReceipt.freight_total}
                                />
                                <Detail
                                    label="Additional charges"
                                    value={
                                        lorryReceipt.additional_charges_total
                                    }
                                />
                                <Detail
                                    label="Goods value"
                                    value={lorryReceipt.goods_value || '—'}
                                />
                            </section>
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="goods" activeId={tab}>
                        <FormSection title="Goods" columns={1}>
                            <section className="space-y-3">
                                <h2 className="sr-only">Goods</h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[640px] text-left text-sm">
                                        <thead className="text-muted-foreground">
                                            <tr className="border-b border-border">
                                                <th className="px-2 py-2 font-medium">
                                                    Description
                                                </th>
                                                <th className="px-2 py-2 font-medium">
                                                    Pkgs
                                                </th>
                                                <th className="px-2 py-2 font-medium">
                                                    Weight
                                                </th>
                                                <th className="px-2 py-2 font-medium">
                                                    Rate
                                                </th>
                                                <th className="px-2 py-2 font-medium">
                                                    Freight
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {lorryReceipt.goods.map((row) => (
                                                <tr
                                                    key={row.id}
                                                    className="border-b border-border/70"
                                                >
                                                    <td className="px-2 py-2">
                                                        {row.goods_description ||
                                                            '—'}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {row.package_count ||
                                                            '—'}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {row.actual_weight ||
                                                            '—'}{' '}
                                                        /{' '}
                                                        {row.charged_weight ||
                                                            '—'}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {row.freight_rate_type.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}{' '}
                                                        @ {row.freight_rate}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {row.freight_amount}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </FormSection>
                    </DetailTabPanel>

                    <DetailTabPanel id="documents" activeId={tab}>
                        <section className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    E-way bills
                                </h2>
                                {lorryReceipt.eway_bills.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        None
                                    </p>
                                ) : (
                                    <ul className="space-y-2 text-sm">
                                        {lorryReceipt.eway_bills.map((bill) => (
                                            <li key={bill.id}>
                                                {bill.ewb_number} · valid until{' '}
                                                {bill.valid_until}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            <div className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Additional charges
                                </h2>
                                {lorryReceipt.additional_charges.length ===
                                0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        None
                                    </p>
                                ) : (
                                    <ul className="space-y-2 text-sm">
                                        {lorryReceipt.additional_charges.map(
                                            (charge) => (
                                                <li
                                                    key={charge.id}
                                                    className="flex justify-between gap-3"
                                                >
                                                    <span>
                                                        {charge.description}
                                                    </span>
                                                    <span>{charge.amount}</span>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </div>
                        </section>

                        {permissions.can_cancel &&
                        lorryReceipt.status !== 'cancelled' &&
                        lorryReceipt.status !== 'delivered' ? (
                            <section className="space-y-3 rounded-xl border border-border bg-card p-4 md:p-5">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Cancel LR
                                </h2>
                                <Form
                                    action={urls.cancel}
                                    method="post"
                                    className="grid gap-3 md:max-w-xl"
                                >
                                    {({ processing }) => (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="remark">
                                                    Remark
                                                </Label>
                                                <Input
                                                    id="remark"
                                                    name="remark"
                                                    required
                                                />
                                                {errors?.remark ? (
                                                    <p className="text-sm text-destructive">
                                                        {errors.remark}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Cancel Lorry Receipt
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </section>
                        ) : null}

                        {lorryReceipt.cancellation_remark ? (
                            <p className="text-sm text-muted-foreground">
                                Cancellation remark:{' '}
                                {lorryReceipt.cancellation_remark}
                            </p>
                        ) : null}
                    </DetailTabPanel>

                    <DetailTabPanel id="files" activeId={tab}>
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Supporting documents and optional POD for this
                                Lorry Receipt.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {documentDrawer ? (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setDocsOpen(true)}
                                    >
                                        Manage documents
                                    </Button>
                                ) : urls.documents ? (
                                    <Button asChild variant="secondary">
                                        <Link href={urls.documents}>
                                            Open document manager
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                            {documentDrawer ? (
                                <ul className="divide-y divide-border rounded-lg border border-border text-sm">
                                    {documentDrawer.attachments.length === 0 ? (
                                        <li className="px-3 py-2 text-muted-foreground">
                                            No files attached yet.
                                        </li>
                                    ) : (
                                        documentDrawer.attachments.map(
                                            (attachment) => (
                                                <li
                                                    key={attachment.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                                >
                                                    <span>
                                                        {attachment.label ||
                                                            attachment.file
                                                                ?.original_name ||
                                                            attachment.document_type ||
                                                            'Attachment'}
                                                    </span>
                                                    {attachment.download_url ? (
                                                        <a
                                                            className="text-sm text-[#2A6FDB] underline"
                                                            href={
                                                                attachment.download_url
                                                            }
                                                        >
                                                            Download
                                                        </a>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            Unavailable
                                                        </span>
                                                    )}
                                                </li>
                                            ),
                                        )
                                    )}
                                </ul>
                            ) : null}
                        </div>
                    </DetailTabPanel>
                </DetailTabs>
            </PageShell>
            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    title="LR documents / POD"
                    {...documentDrawer}
                />
            ) : null}
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="text-sm">{value}</p>
        </div>
    );
}
