import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ArrowLeft,
    FileText,
    FolderOpen,
    Pencil,
    Printer,
    Truck,
    Wallet,
} from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    FbCompactTable,
    FbDetailCard,
    FbDetailLayout,
    FbDetailPage,
    FbLrFinancialSummary,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatDisplayDate } from '@/lib/dates';
import { formatMtLabel, kgToMt } from '@/lib/operational-weight';
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
    billing_customer_name?: string | null;
    goods_value: string | null;
    freight_total: string;
    additional_charges_total: string;
    cancellation_remark: string | null;
    delivered_at?: string | null;
    cancelled_at?: string | null;
    goods: Array<{
        id: string;
        goods_type_name?: string | null;
        goods_description: string | null;
        goods_label?: string | null;
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
        expenses_url?: string;
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

function parseAmount(value: string | null | undefined): number | null {
    if (value == null || value === '' || value === '—') {
        return null;
    }

    const normalized = String(value).replace(/[₹,\s]/g, '');
    const parsed = Number(normalized);

    return Number.isFinite(parsed) ? parsed : null;
}

function formatTotal(freight: string, additional: string): string {
    const freightAmount = parseAmount(freight);
    const additionalAmount = parseAmount(additional);

    if (freightAmount === null && additionalAmount === null) {
        return '—';
    }

    return ((freightAmount ?? 0) + (additionalAmount ?? 0)).toFixed(2);
}

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
    const [docsOpen, setDocsOpen] = useState(false);
    const isPending = lorryReceipt.status === 'pending';

    const fromLabel =
        lorryReceipt.from_station_name || lorryReceipt.from_text || '—';
    const toLabel = lorryReceipt.to_station_name || lorryReceipt.to_text || '—';

    const freightGrandTotal = useMemo(
        () =>
            formatTotal(
                lorryReceipt.freight_total,
                lorryReceipt.additional_charges_total,
            ),
        [lorryReceipt.freight_total, lorryReceipt.additional_charges_total],
    );

    const tripManageUrl = linkedTrip
        ? (linkedTrip.expenses_url ?? `${linkedTrip.show_url}#expenses`)
        : null;

    return (
        <>
            <Head title={`Bilty ${lorryReceipt.lr_number}`} />
            <FbDetailPage title={`Bilty ${lorryReceipt.lr_number}`}>
                {lorryReceipt.status === 'cancelled' ? (
                    <div
                        className="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
                        role="alert"
                    >
                        <strong>Cancelled bilty</strong>
                        <div className="text-xs">
                            {lorryReceipt.cancellation_remark ??
                                'This consignment note cannot be modified.'}
                        </div>
                    </div>
                ) : null}

                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FbDetailLayout
                    main={
                        <>
                            <FbDetailCard
                                title={`Bilty Information (${lorryReceipt.lr_number})`}
                                badges={
                                    <WorkspaceStatusBadge
                                        status={lorryReceipt.status}
                                    />
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
                                            icon={Printer}
                                            label="Print bilty"
                                            href={urls.print}
                                            variant="secondary"
                                            size="sm"
                                        />
                                        {permissions.can_edit && isPending ? (
                                            <AppIconAction
                                                icon={Pencil}
                                                label="Edit bilty"
                                                href={urls.edit}
                                                variant="warning"
                                                size="sm"
                                            />
                                        ) : null}
                                        {permissions.can_create_trip &&
                                        urls.create_trip ? (
                                            <AppIconAction
                                                icon={Wallet}
                                                label="Create Trip"
                                                href={urls.create_trip}
                                                variant="default"
                                                size="sm"
                                            />
                                        ) : null}
                                        {linkedTrip && tripManageUrl ? (
                                            <AppIconAction
                                                icon={Wallet}
                                                label="Manage Expenses"
                                                href={tripManageUrl}
                                                variant="default"
                                                size="sm"
                                            />
                                        ) : null}
                                        {permissions.can_create_lorry_hire &&
                                        urls.create_lorry_hire ? (
                                            <AppIconAction
                                                icon={Truck}
                                                label="Create Lorry Hire"
                                                href={urls.create_lorry_hire}
                                                variant="default"
                                                size="sm"
                                            />
                                        ) : null}
                                        {linkedLorryHire ? (
                                            <AppIconAction
                                                icon={Truck}
                                                label={`Manage Lorry Hire ${linkedLorryHire.hire_number}`}
                                                href={linkedLorryHire.show_url}
                                                variant="default"
                                                size="sm"
                                            />
                                        ) : null}
                                        {documentDrawer ? (
                                            <AppIconAction
                                                icon={FolderOpen}
                                                label="Documents / POD"
                                                onClick={() =>
                                                    setDocsOpen(true)
                                                }
                                                variant="info"
                                                size="sm"
                                            />
                                        ) : urls.documents ? (
                                            <AppIconAction
                                                icon={FolderOpen}
                                                label="Documents"
                                                href={urls.documents}
                                                variant="info"
                                                size="sm"
                                            />
                                        ) : null}
                                    </>
                                }
                            >
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Bilty No."
                                        value={lorryReceipt.lr_number}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="GR Date"
                                        value={formatDisplayDate(
                                            lorryReceipt.lr_date,
                                        )}
                                    />
                                    <FbInfoItem
                                        label="Service"
                                        value={workflowContextLabel(
                                            lorryReceipt.workflow_context,
                                        )}
                                    />
                                    <FbInfoItem
                                        label="Status"
                                        value={lorryReceipt.status}
                                    />
                                    <FbInfoItem
                                        label="Loading"
                                        value={fromLabel}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="Unloading"
                                        value={toLabel}
                                        accent
                                    />
                                    <FbInfoItem
                                        label="Consignor"
                                        value={lorryReceipt.consignor_name}
                                    />
                                    <FbInfoItem
                                        label="Consignee"
                                        value={lorryReceipt.consignee_name}
                                    />
                                    <FbInfoItem
                                        label="Goods value"
                                        value={lorryReceipt.goods_value}
                                    />
                                    <FbInfoItem
                                        label="Freight total"
                                        value={lorryReceipt.freight_total}
                                    />
                                    <FbInfoItem
                                        label="Additional charges"
                                        value={
                                            lorryReceipt.additional_charges_total
                                        }
                                    />
                                </FbInfoGrid>
                            </FbDetailCard>

                            <FbCompactTable
                                title="Goods"
                                count={lorryReceipt.goods.length}
                                headers={[
                                    'Description',
                                    'Pkgs',
                                    'Weight (act. / chg.)',
                                    'Rate',
                                    'Freight',
                                ]}
                                empty="No goods lines on this bilty."
                            >
                                {lorryReceipt.goods.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className="px-3 py-2">
                                            {row.goods_label ||
                                                row.goods_description ||
                                                row.goods_type_name ||
                                                '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.package_count ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {formatMtLabel(
                                                kgToMt(row.actual_weight),
                                            )}{' '}
                                            /{' '}
                                            {formatMtLabel(
                                                kgToMt(row.charged_weight),
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.freight_rate_type.replaceAll(
                                                '_',
                                                ' ',
                                            )}{' '}
                                            @ {row.freight_rate}
                                        </td>
                                        <td className="px-3 py-2 font-medium">
                                            {row.freight_amount}
                                        </td>
                                    </tr>
                                ))}
                            </FbCompactTable>

                            <div className="grid gap-3 md:grid-cols-2">
                                <FbDetailCard title="E-way bills">
                                    {lorryReceipt.eway_bills.length === 0 ? (
                                        <p className="px-4 py-3 text-sm text-slate-500">
                                            None recorded.
                                        </p>
                                    ) : (
                                        <ul className="divide-y divide-slate-100 text-sm">
                                            {lorryReceipt.eway_bills.map(
                                                (bill) => (
                                                    <li
                                                        key={bill.id}
                                                        className="flex flex-col gap-0.5 px-4 py-2.5"
                                                    >
                                                        <span className="font-medium text-slate-800">
                                                            {bill.ewb_number}
                                                        </span>
                                                        <span className="text-xs text-slate-500">
                                                            Issued{' '}
                                                            {formatDisplayDate(
                                                                bill.ewb_date,
                                                            )}{' '}
                                                            · Valid until{' '}
                                                            {formatDisplayDate(
                                                                bill.valid_until,
                                                            )}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </FbDetailCard>

                                <FbDetailCard title="Additional charges">
                                    {lorryReceipt.additional_charges.length ===
                                    0 ? (
                                        <p className="px-4 py-3 text-sm text-slate-500">
                                            None recorded.
                                        </p>
                                    ) : (
                                        <ul className="divide-y divide-slate-100 text-sm">
                                            {lorryReceipt.additional_charges.map(
                                                (charge) => (
                                                    <li
                                                        key={charge.id}
                                                        className="flex items-center justify-between gap-3 px-4 py-2.5"
                                                    >
                                                        <span>
                                                            {charge.description}
                                                        </span>
                                                        <span className="font-medium tabular-nums">
                                                            {charge.amount}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </FbDetailCard>
                            </div>

                            {documentDrawer ? (
                                <FbDetailCard title="Documents / POD">
                                    <div className="space-y-3 px-4 py-3">
                                        <p className="text-sm text-slate-600">
                                            Supporting documents and optional
                                            proof of delivery for this bilty.
                                        </p>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => setDocsOpen(true)}
                                        >
                                            <FileText
                                                className="mr-2 size-4"
                                                aria-hidden
                                            />
                                            Manage documents
                                        </Button>
                                        <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                            {documentDrawer.attachments
                                                .length === 0 ? (
                                                <li className="px-3 py-2 text-slate-500">
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
                                                                    attachment
                                                                        .file
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
                                                                <span className="text-xs text-slate-500">
                                                                    Unavailable
                                                                </span>
                                                            )}
                                                        </li>
                                                    ),
                                                )
                                            )}
                                        </ul>
                                    </div>
                                </FbDetailCard>
                            ) : null}

                            {permissions.can_cancel &&
                            lorryReceipt.status !== 'cancelled' &&
                            lorryReceipt.status !== 'delivered' ? (
                                <FbDetailCard title="Cancel bilty">
                                    <Form
                                        action={urls.cancel}
                                        method="post"
                                        className="grid max-w-xl gap-3 px-4 py-3"
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
                                                    Cancel lorry receipt
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </FbDetailCard>
                            ) : null}

                            {permissions.can_delete && isPending ? (
                                <FbDetailCard title="Delete bilty">
                                    <div className="space-y-3 px-4 py-3">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            onClick={() =>
                                                setConfirmDelete(
                                                    (value) => !value,
                                                )
                                            }
                                        >
                                            {confirmDelete
                                                ? 'Hide delete form'
                                                : 'Delete pending bilty'}
                                        </Button>
                                        {confirmDelete ? (
                                            <Form
                                                action={urls.destroy}
                                                method="delete"
                                                className="rounded-lg border border-destructive/40 bg-destructive/5 p-4"
                                            >
                                                {({ processing }) => (
                                                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                        <p className="text-sm">
                                                            Soft-delete this
                                                            pending bilty? This
                                                            cannot be undone
                                                            from the UI.
                                                        </p>
                                                        <div className="flex gap-2">
                                                            <Button
                                                                type="button"
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    setConfirmDelete(
                                                                        false,
                                                                    )
                                                                }
                                                            >
                                                                Keep
                                                            </Button>
                                                            <Button
                                                                type="submit"
                                                                variant="destructive"
                                                                disabled={
                                                                    processing
                                                                }
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
                                    </div>
                                </FbDetailCard>
                            ) : null}
                        </>
                    }
                    side={
                        <>
                            <FbLrFinancialSummary
                                billingCustomerName={
                                    lorryReceipt.billing_customer_name
                                }
                                customerFreight={lorryReceipt.freight_total}
                                additionalCharges={
                                    lorryReceipt.additional_charges_total
                                }
                                totalBillable={freightGrandTotal}
                                goodsValue={lorryReceipt.goods_value ?? '—'}
                                hirePending={
                                    lorryReceipt.workflow_context ===
                                        'broker' && linkedLorryHire === null
                                }
                                transporterSummary={
                                    linkedLorryHire ? (
                                        <Link
                                            href={linkedLorryHire.show_url}
                                            className="font-medium text-[#2A6FDB] underline"
                                        >
                                            Lorry Hire{' '}
                                            {linkedLorryHire.hire_number}
                                        </Link>
                                    ) : undefined
                                }
                            />
                            <FbDetailCard title="Record Info">
                                <FbInfoGrid>
                                    <FbInfoItem
                                        label="Bilty No."
                                        value={lorryReceipt.lr_number}
                                    />
                                    <FbInfoItem
                                        label="GR Date"
                                        value={formatDisplayDate(
                                            lorryReceipt.lr_date,
                                        )}
                                    />
                                    <FbInfoItem
                                        label="Service"
                                        value={workflowContextLabel(
                                            lorryReceipt.workflow_context,
                                        )}
                                    />
                                    <FbInfoItem
                                        label="Status"
                                        value={lorryReceipt.status}
                                    />
                                    {linkedTrip ? (
                                        <FbInfoItem
                                            label="Linked trip"
                                            value={
                                                <Link
                                                    href={tripManageUrl!}
                                                    className="text-[#2A6FDB] underline"
                                                >
                                                    {linkedTrip.status}
                                                </Link>
                                            }
                                        />
                                    ) : null}
                                    {linkedLorryHire ? (
                                        <FbInfoItem
                                            label="Linked hire"
                                            value={
                                                <Link
                                                    href={
                                                        linkedLorryHire.show_url
                                                    }
                                                    className="text-[#2A6FDB] underline"
                                                >
                                                    {
                                                        linkedLorryHire.hire_number
                                                    }
                                                </Link>
                                            }
                                        />
                                    ) : null}
                                    {lorryReceipt.delivered_at ? (
                                        <FbInfoItem
                                            label="Delivered"
                                            value={formatDisplayDate(
                                                lorryReceipt.delivered_at,
                                            )}
                                        />
                                    ) : null}
                                    {lorryReceipt.cancelled_at ? (
                                        <FbInfoItem
                                            label="Cancelled"
                                            value={formatDisplayDate(
                                                lorryReceipt.cancelled_at,
                                            )}
                                        />
                                    ) : null}
                                </FbInfoGrid>
                            </FbDetailCard>
                        </>
                    }
                />
            </FbDetailPage>
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
