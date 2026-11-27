import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EligibleRow = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    billing_customer_party_id: string | null;
    billing_customer_name: string | null;
    delivery_status: string | null;
    memo_number: string | null;
    urls: { complete: string };
};

type RecentRow = {
    id: string;
    lr_number: string | null;
    status: string;
    memo_number: string | null;
    pod_number: string | null;
    delivery_date: string | null;
    urls: { pod: string; documents: string };
    documentDrawer: Omit<DocumentDrawerProps, 'open' | 'onOpenChange'> | null;
};

type Props = {
    permissions: { can_view: boolean; can_manage: boolean };
    eligible: {
        data: EligibleRow[];
        meta: { total: number };
    };
    recentDeliveries: RecentRow[];
    urls: { index: string; lorry_hires: string };
};

export default function DeliveriesIndex({
    permissions,
    eligible,
    recentDeliveries,
    urls,
}: Props) {
    const [docsFor, setDocsFor] = useState<RecentRow | null>(null);
    const podForm = useForm<{ file: File | null; label: string }>({
        file: null,
        label: '',
    });

    const podSubmitAllowed = useRef(false);
    const [podConfirmOpen, setPodConfirmOpen] = useState(false);
    const [podReviewFields, setPodReviewFields] = useState<FieldReview[]>([]);
    const [podTarget, setPodTarget] = useState<RecentRow | null>(null);

    const submitPod = (event: React.FormEvent, row: RecentRow) => {
        event.preventDefault();
        if (!podForm.data.file) {
            return;
        }
        if (!podSubmitAllowed.current) {
            setPodTarget(row);
            setPodReviewFields([
                { label: 'Bilty No.', value: row.lr_number ?? '—' },
                { label: 'Memo number', value: row.memo_number ?? '—' },
                { label: 'POD number', value: row.pod_number ?? '—' },
                {
                    label: 'File',
                    value: podForm.data.file.name,
                },
                {
                    label: 'Label',
                    value: podForm.data.label.trim() || '—',
                },
            ]);
            setPodConfirmOpen(true);
            return;
        }
        podSubmitAllowed.current = false;
        podForm.post(row.urls.pod, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                podForm.reset();
                setDocsFor(null);
                setPodTarget(null);
            },
        });
    };

    return (
        <>
            <Head title="Broker Delivery & Memo" />
            <AppIndexPage className="space-y-3">
                <AppIndexListCard title="Bilties awaiting delivery">
                    <p className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-slate-600">
                        Assign a Memo / POD from the{' '}
                        <Link
                            href={urls.lorry_hires}
                            className="font-medium text-[#1d4ed8] underline"
                        >
                            Good Hires register
                        </Link>{' '}
                        (select Bilties, then Memo / POD); it marks them
                        delivered. Use Deliver below only for a Bilty delivered
                        without a memo.
                    </p>
                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full text-sm">
                            <thead>
                                <tr>
                                    <th className="px-4 py-3">LR</th>
                                    <th className="px-4 py-3">Date</th>
                                    <th className="px-4 py-3">
                                        Billing customer
                                    </th>
                                    <th className="px-4 py-3">Memo</th>
                                    <th className="px-4 py-3">Status</th>
                                    {permissions.can_manage ? (
                                        <th className="px-4 py-3">
                                            Deliver without memo
                                        </th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {eligible.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                permissions.can_manage ? 6 : 5
                                            }
                                            className="px-4 py-6 text-center text-sm text-muted-foreground"
                                        >
                                            No Bilties are awaiting delivery.
                                        </td>
                                    </tr>
                                ) : null}
                                {eligible.data.map((row) => (
                                    <EligibleRowView
                                        key={row.id}
                                        row={row}
                                        canManage={permissions.can_manage}
                                    />
                                ))}
                            </tbody>
                        </table>
                        <p className="px-4 py-2 text-xs text-muted-foreground">
                            {eligible.meta.total} eligible LR
                            {eligible.meta.total === 1 ? '' : 's'}
                        </p>
                    </div>
                </AppIndexListCard>

                {recentDeliveries.length > 0 ? (
                    <AppIndexListCard title="Recent activity & POD files">
                        <ul className="space-y-3 p-4 text-sm">
                            {recentDeliveries.map((row) => (
                                <li
                                    key={row.id}
                                    className="rounded-md border border-border px-3 py-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {row.lr_number ?? '—'}
                                        </span>
                                        <WorkspaceStatusBadge
                                            status={row.status}
                                        />
                                        {row.memo_number ? (
                                            <span className="text-muted-foreground">
                                                Memo {row.memo_number}
                                            </span>
                                        ) : null}
                                        {row.pod_number ? (
                                            <span className="text-muted-foreground">
                                                POD # {row.pod_number}
                                            </span>
                                        ) : null}
                                        {row.delivery_date ? (
                                            <span className="text-muted-foreground">
                                                Delivered {row.delivery_date}
                                            </span>
                                        ) : null}
                                        {row.documentDrawer ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                onClick={() => setDocsFor(row)}
                                            >
                                                Documents (
                                                {
                                                    row.documentDrawer
                                                        .attachments.length
                                                }
                                                )
                                            </Button>
                                        ) : null}
                                    </div>
                                    {permissions.can_manage &&
                                    (row.status === 'memo_assigned' ||
                                        row.status === 'delivered') ? (
                                        <form
                                            id={`pod-upload-${row.id}`}
                                            className="mt-3 flex flex-wrap items-end gap-2"
                                            onSubmit={(event) =>
                                                submitPod(event, row)
                                            }
                                        >
                                            <div>
                                                <Label
                                                    htmlFor={`pod-file-${row.id}`}
                                                >
                                                    Upload POD file
                                                </Label>
                                                <Input
                                                    id={`pod-file-${row.id}`}
                                                    type="file"
                                                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                                                    onChange={(e) =>
                                                        podForm.setData(
                                                            'file',
                                                            e.target
                                                                .files?.[0] ??
                                                                null,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label
                                                    htmlFor={`pod-label-${row.id}`}
                                                >
                                                    Label
                                                </Label>
                                                <Input
                                                    id={`pod-label-${row.id}`}
                                                    value={podForm.data.label}
                                                    onChange={(e) =>
                                                        podForm.setData(
                                                            'label',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Optional"
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                                disabled={
                                                    podForm.processing ||
                                                    !podForm.data.file
                                                }
                                            >
                                                Upload POD
                                            </Button>
                                        </form>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </AppIndexListCard>
                ) : null}
            </AppIndexPage>
            {docsFor?.documentDrawer ? (
                <DocumentDrawer
                    open={docsFor !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setDocsFor(null);
                        }
                    }}
                    {...docsFor.documentDrawer}
                    title={`${docsFor.documentDrawer.title} · ${docsFor.lr_number ?? docsFor.id}`}
                />
            ) : null}

            <FormConfirmationDialog
                open={podConfirmOpen}
                onOpenChange={(open) => {
                    setPodConfirmOpen(open);
                    if (!open) {
                        setPodTarget(null);
                    }
                }}
                mode="create"
                title="Upload this POD document?"
                description="Confirm Bilty context and file before uploading to shared Document Management."
                fields={podReviewFields}
                onConfirm={() => {
                    if (!podTarget) {
                        return;
                    }
                    podSubmitAllowed.current = true;
                    setPodConfirmOpen(false);
                    window.setTimeout(() => {
                        const formElement = document.getElementById(
                            `pod-upload-${podTarget.id}`,
                        ) as HTMLFormElement | null;
                        formElement?.requestSubmit();
                    }, 0);
                }}
            />
        </>
    );
}

function EligibleRowView({
    row,
    canManage,
}: {
    row: EligibleRow;
    canManage: boolean;
}) {
    const completeForm = useForm({
        delivery_date: new Date().toISOString().slice(0, 10),
    });
    const deliverAllowed = useRef(false);
    const [deliverConfirmOpen, setDeliverConfirmOpen] = useState(false);
    const [deliverFields, setDeliverFields] = useState<FieldReview[]>([]);

    return (
        <tr className="border-t border-border">
            <td className="px-4 py-3 font-medium">{row.lr_number}</td>
            <td className="px-4 py-3">{row.lr_date ?? '—'}</td>
            <td className="px-4 py-3">{row.billing_customer_name ?? '—'}</td>
            <td className="px-4 py-3">{row.memo_number ?? '—'}</td>
            <td className="px-4 py-3">
                {row.delivery_status ? (
                    <WorkspaceStatusBadge status={row.delivery_status} />
                ) : (
                    '—'
                )}
            </td>
            {canManage ? (
                <td className="px-4 py-3">
                    <>
                        <form
                            id={`deliver-${row.id}`}
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (!deliverAllowed.current) {
                                    setDeliverFields([
                                        {
                                            label: 'Bilty No.',
                                            value: row.lr_number,
                                        },
                                        {
                                            label: 'Customer',
                                            value:
                                                row.billing_customer_name ??
                                                '—',
                                        },
                                        {
                                            label: 'Memo number',
                                            value: row.memo_number ?? '—',
                                        },
                                        {
                                            label: 'Delivery date',
                                            value:
                                                completeForm.data
                                                    .delivery_date || '—',
                                        },
                                    ]);
                                    setDeliverConfirmOpen(true);
                                    return;
                                }
                                deliverAllowed.current = false;
                                completeForm.post(row.urls.complete, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Input
                                type="date"
                                value={completeForm.data.delivery_date}
                                onChange={(e) =>
                                    completeForm.setData(
                                        'delivery_date',
                                        e.target.value,
                                    )
                                }
                                required
                                aria-label={`Delivery date for ${row.lr_number}`}
                                className="w-auto"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                disabled={completeForm.processing}
                            >
                                Deliver
                            </Button>
                        </form>
                        <FormConfirmationDialog
                            open={deliverConfirmOpen}
                            onOpenChange={setDeliverConfirmOpen}
                            mode="create"
                            title="Deliver without Memo / POD?"
                            description="This delivery status cannot be changed again. A Memo / POD can no longer group this Bilty for invoicing."
                            fields={deliverFields}
                            processing={completeForm.processing}
                            onConfirm={() => {
                                deliverAllowed.current = true;
                                setDeliverConfirmOpen(false);
                                window.setTimeout(() => {
                                    const formElement = document.getElementById(
                                        `deliver-${row.id}`,
                                    ) as HTMLFormElement | null;
                                    formElement?.requestSubmit();
                                }, 0);
                            }}
                        />
                    </>
                </td>
            ) : null}
        </tr>
    );
}
