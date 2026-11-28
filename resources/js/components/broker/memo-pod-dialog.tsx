import { useForm, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatInr } from '@/lib/money';

type MemoContext = {
    customer_name: string | null;
    today: string;
    receipts: Array<{
        id: string;
        lr_number: string;
        lr_date: string | null;
        vehicle_registration: string | null;
        freight_total: string | null;
        shortage_damage_amount: string | null;
    }>;
};

type MemoForm = {
    lorry_receipt_ids: string[];
    memo_number: string;
    memo_date: string;
    pod_documents: Record<string, File>;
};

export type MemoPodUrls = { memo_context: string; memo_store: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lorryReceiptIds: string[];
    urls: MemoPodUrls;
    onAssigned?: (lorryReceiptIds: string[]) => void;
};

function money(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '—' : formatInr(value);
}

/** Legacy "Memo / POD": one memo reference for Bilties of one customer; assigning it marks them Delivered. */
export function MemoPodDialog({
    open,
    onOpenChange,
    lorryReceiptIds,
    urls,
    onAssigned,
}: Props) {
    const [context, setContext] = useState<MemoContext | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [attempted, setAttempted] = useState(false);
    const contextRequest = useHttp<Record<string, never>, MemoContext>({});
    const form = useForm<MemoForm>({
        lorry_receipt_ids: [],
        memo_number: '',
        memo_date: '',
        pod_documents: {},
    });

    const idsKey = lorryReceiptIds.join(',');

    useEffect(() => {
        if (!open || idsKey === '') {
            return;
        }
        setContext(null);
        setLoadError(null);
        setAttempted(false);
        form.clearErrors();
        const query = new URLSearchParams();
        for (const id of idsKey.split(',')) {
            query.append('lorry_receipt_ids[]', id);
        }
        void contextRequest.get(`${urls.memo_context}?${query}`, {
            onSuccess: (payload) => {
                setContext(payload);
                form.setData({
                    lorry_receipt_ids: payload.receipts.map((row) => row.id),
                    memo_number: '',
                    memo_date: payload.today,
                    pod_documents: {},
                });
            },
            onError: (errors) => {
                setLoadError(
                    Object.values(errors)[0] ??
                        'These Bilties cannot share a Memo / POD.',
                );
            },
            onHttpException: () => {
                setLoadError('Unable to load the selected Bilties. Try again.');

                return false;
            },
            onNetworkError: () => {
                setLoadError('Network error while loading Bilties. Try again.');

                return false;
            },
        });
        // Reload only when the dialog opens for a different selection.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, idsKey]);

    const receipts = context?.receipts ?? [];
    const memoMissing = form.data.memo_number.trim() === '';
    const futureDate = context !== null && form.data.memo_date > context.today;
    const clientErrors: Partial<Record<keyof MemoForm, string>> = {
        ...(memoMissing ? { memo_number: 'Enter the Memo / POD number.' } : {}),
        ...(futureDate
            ? { memo_date: 'The memo date cannot be in the future.' }
            : {}),
    };
    const errorFor = (key: keyof MemoForm): string | undefined =>
        form.errors[key] ??
        (attempted || key === 'memo_date' ? clientErrors[key] : undefined);
    const documentError = (id: string): string | undefined =>
        (form.errors as Record<string, string | undefined>)[
            `pod_documents.${id}`
        ];
    const totalFreight = receipts.reduce(
        (sum, row) => sum + Number(row.freight_total ?? 0),
        0,
    );

    const submit = () => {
        form.post(urls.memo_store, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setConfirmOpen(false);
                onOpenChange(false);
                onAssigned?.(receipts.map((row) => row.id));
            },
            onError: () => setConfirmOpen(false),
        });
    };

    return (
        <>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!form.processing) {
                        onOpenChange(next);
                    }
                }}
            >
                <DialogContent
                    className="fb-pay-dialog gap-0 overflow-hidden border border-slate-200 p-0 sm:max-w-4xl"
                    data-testid="memo-pod-modal"
                >
                    <DialogHeader className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-left">
                        <DialogTitle className="text-base font-extrabold text-slate-900">
                            {context
                                ? `Bulk Memo / POD ID Assignment — ${receipts.length} ${receipts.length === 1 ? 'Bilty' : 'Bilties'}`
                                : 'Memo / POD'}
                        </DialogTitle>
                    </DialogHeader>

                    {loadError ? (
                        <p
                            role="alert"
                            className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                        >
                            {loadError}
                        </p>
                    ) : null}

                    {!context && !loadError ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            Loading Bilties…
                        </p>
                    ) : null}

                    {context ? (
                        <form
                            id="memo-pod-form"
                            className="fb-pay-dialog__body space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                setAttempted(true);
                                if (Object.keys(clientErrors).length === 0) {
                                    setConfirmOpen(true);
                                }
                            }}
                        >
                            <p className="text-sm">
                                <span className="text-slate-500">
                                    Customer:
                                </span>{' '}
                                <strong>{context.customer_name ?? '—'}</strong>
                            </p>

                            <div
                                className="grid gap-4 sm:grid-cols-2"
                                data-testid="memo-panel-header-fields"
                            >
                                <div className="space-y-1">
                                    <Label htmlFor="memo_number" required>
                                        Memo / POD No.
                                    </Label>
                                    <Input
                                        id="memo_number"
                                        maxLength={64}
                                        autoComplete="off"
                                        value={form.data.memo_number}
                                        aria-invalid={
                                            errorFor('memo_number')
                                                ? true
                                                : undefined
                                        }
                                        onChange={(event) => {
                                            form.setData(
                                                'memo_number',
                                                event.target.value,
                                            );
                                            form.clearErrors('memo_number');
                                        }}
                                    />
                                    <FieldError
                                        message={errorFor('memo_number')}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="memo_date">Memo Date</Label>
                                    <Input
                                        id="memo_date"
                                        type="date"
                                        max={context.today}
                                        value={form.data.memo_date}
                                        onChange={(event) =>
                                            form.setData(
                                                'memo_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <FieldError
                                        message={errorFor('memo_date')}
                                    />
                                </div>
                            </div>

                            <div className="fb-pay-panel fb-pay-panel--alloc overflow-hidden">
                                <div className="fb-pay-panel__head">
                                    Selected Bilties
                                </div>
                                <div className="fb-pay-panel__body--flush overflow-x-auto">
                                    <table className="fb-pay-table mb-0 w-full">
                                        <thead>
                                            <tr>
                                                <th className="px-3 py-2">
                                                    LR No
                                                </th>
                                                <th className="px-3 py-2">
                                                    Date
                                                </th>
                                                <th className="px-3 py-2">
                                                    Truck
                                                </th>
                                                <th className="px-3 py-2 text-end">
                                                    Freight
                                                </th>
                                                <th className="px-3 py-2 text-end">
                                                    Shortage / Damage
                                                </th>
                                                <th className="px-3 py-2">
                                                    POD Document
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {receipts.map((row) => (
                                                <tr
                                                    key={row.id}
                                                    className="border-t border-slate-100 align-top"
                                                >
                                                    <td className="px-3 py-2 font-medium">
                                                        {row.lr_number}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.lr_date ?? '—'}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.vehicle_registration ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-3 py-2 text-end tabular-nums">
                                                        {money(
                                                            row.freight_total,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 text-end tabular-nums">
                                                        {money(
                                                            row.shortage_damage_amount,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <input
                                                            type="file"
                                                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                                                            aria-label={`POD document for ${row.lr_number} (optional)`}
                                                            className="max-w-[12rem] text-xs"
                                                            onChange={(
                                                                event,
                                                            ) => {
                                                                const file =
                                                                    event.target
                                                                        .files?.[0];
                                                                const next = {
                                                                    ...form.data
                                                                        .pod_documents,
                                                                };
                                                                if (file) {
                                                                    next[
                                                                        row.id
                                                                    ] = file;
                                                                } else {
                                                                    delete next[
                                                                        row.id
                                                                    ];
                                                                }
                                                                form.setData(
                                                                    'pod_documents',
                                                                    next,
                                                                );
                                                            }}
                                                        />
                                                        <FieldError
                                                            message={documentError(
                                                                row.id,
                                                            )}
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t border-slate-200 bg-slate-50 font-semibold">
                                                <td
                                                    className="px-3 py-2 text-end"
                                                    colSpan={3}
                                                >
                                                    Total Freight
                                                </td>
                                                <td className="px-3 py-2 text-end tabular-nums">
                                                    {formatInr(totalFreight)}
                                                </td>
                                                <td colSpan={2} />
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <p className="text-xs text-slate-500">
                                POD documents are optional (PDF or image, up to
                                2 MB) and do not change the delivery status.
                            </p>
                            <FieldError
                                message={
                                    (
                                        form.errors as Record<
                                            string,
                                            string | undefined
                                        >
                                    ).lorry_receipt_ids
                                }
                            />
                        </form>
                    ) : null}

                    <DialogFooter className="fb-pay-dialog__footer">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            Close
                        </Button>
                        {context ? (
                            <Button
                                type="submit"
                                form="memo-pod-form"
                                disabled={
                                    form.processing ||
                                    (attempted &&
                                        Object.keys(clientErrors).length > 0)
                                }
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Review Memo / POD
                            </Button>
                        ) : null}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {context ? (
                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Assign this Memo / POD?"
                    description="The selected Bilties will be marked Delivered and grouped under this memo for invoicing."
                    processing={form.processing}
                    confirmLabel="Assign Memo / POD"
                    onConfirm={submit}
                    sections={[
                        {
                            title: 'Memo / POD',
                            fields: [
                                {
                                    label: 'Customer',
                                    value: context.customer_name ?? '—',
                                },
                                {
                                    label: 'Memo / POD No.',
                                    value: form.data.memo_number.trim(),
                                },
                                {
                                    label: 'Memo Date',
                                    value: form.data.memo_date,
                                },
                                {
                                    label: 'POD documents',
                                    value: String(
                                        Object.keys(form.data.pod_documents)
                                            .length,
                                    ),
                                },
                            ],
                        },
                        {
                            title: 'Bilties',
                            layout: 'full',
                            table: {
                                headers: ['LR No', 'Date', 'Truck', 'Freight'],
                                rows: receipts.map((row) => [
                                    row.lr_number,
                                    row.lr_date ?? '—',
                                    row.vehicle_registration ?? '—',
                                    money(row.freight_total),
                                ]),
                            },
                            fields: [
                                {
                                    label: 'Total Freight',
                                    value: formatInr(totalFreight),
                                    emphasis: 'total',
                                },
                            ],
                        },
                    ]}
                />
            ) : null}
        </>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p role="alert" className="text-xs text-destructive">
            {message}
        </p>
    ) : null;
}
