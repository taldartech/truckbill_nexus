import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import {
    QuickCreateBankCashSheet,
    type QuickCreatedBankCashAccount,
} from '@/components/masters/quick-create-bank-cash-sheet';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type HireOption = {
    id: string;
    hire_number: string;
    lr_number: string | null;
    transporter_name: string | null;
    vehicle_registration: string | null;
    outstanding_amount: string | null;
    allocatable_amount: string | null;
    net_payable?: string | null;
    freight_total?: string | null;
    tds_amount?: string | null;
};

type PreviewLine = {
    lorry_hire_id: string;
    hire_number: string;
    amount: string;
    allocatable: string;
    accepted: boolean;
    reason: string | null;
};

type Props = {
    eligibleLorryHires: HireOption[];
    initialSelectedHireIds?: string[];
    paymentTypeOptions: Array<{ value: string; label: string }>;
    paymentModeOptions: Array<{ value: string; label: string }>;
    bankCashAccounts: Array<{ id: string; name: string }>;
    permissions?: {
        can_create_bank_cash?: boolean;
    };
    urls: {
        index: string;
        create: string;
        bulk_preview: string;
        bulk_store: string;
        bank_cash_store?: string;
    };
};

function parseAmount(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value.trim() === '') {
        return null;
    }
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatMoney(value: number | null): string {
    if (value === null) {
        return '—';
    }
    return `₹${value.toFixed(2)}`;
}

export default function VoucherBulkCreate({
    eligibleLorryHires,
    initialSelectedHireIds = [],
    paymentTypeOptions,
    paymentModeOptions,
    bankCashAccounts: initialBankCashAccounts,
    permissions,
    urls,
}: Props) {
    const page = usePage<{ errors?: Record<string, string> }>();
    const errors = page.props.errors ?? {};

    const seededIds = initialSelectedHireIds.filter((id) =>
        eligibleLorryHires.some((hire) => hire.id === id),
    );

    const [selectedIds, setSelectedIds] = useState<string[]>(seededIds);
    const [amounts, setAmounts] = useState<Record<string, string>>(() => {
        const next: Record<string, string> = {};
        for (const id of seededIds) {
            const hire = eligibleLorryHires.find((row) => row.id === id);
            next[id] = hire?.allocatable_amount ?? '';
        }
        return next;
    });
    const [previewLines, setPreviewLines] = useState<PreviewLine[]>([]);
    const [previewing, setPreviewing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const submitAllowed = useRef(false);
    const [bankCashAccounts, setBankCashAccounts] = useState(
        initialBankCashAccounts,
    );
    const [bankCashQuickCreateOpen, setBankCashQuickCreateOpen] =
        useState(false);
    const canQuickCreateBankCash =
        Boolean(permissions?.can_create_bank_cash) &&
        Boolean(urls.bank_cash_store);

    const form = useForm({
        payment_type: paymentTypeOptions[0]?.value ?? 'advance',
        payment_mode: paymentModeOptions[0]?.value ?? 'bank',
        bank_cash_account_id: '',
        cash_reference: '',
        pump_reference: '',
        remark: '',
    });

    const accountOptions: ComboboxOption[] = useMemo(
        () =>
            bankCashAccounts.map((account) => ({
                id: account.id,
                label: account.name,
            })),
        [bankCashAccounts],
    );

    const selectedHires = useMemo(
        () =>
            eligibleLorryHires.filter((hire) => selectedIds.includes(hire.id)),
        [eligibleLorryHires, selectedIds],
    );

    const transporterKey = selectedHires[0]
        ? `${selectedHires[0].transporter_name ?? ''}|${selectedHires[0].vehicle_registration ?? ''}`
        : '';

    const incompatibleSelection = selectedHires.some(
        (hire) =>
            `${hire.transporter_name ?? ''}|${hire.vehicle_registration ?? ''}` !==
            transporterKey,
    );

    const totalProposed = selectedHires.reduce((sum, hire) => {
        const amount = parseAmount(amounts[hire.id]);
        return sum + (amount ?? 0);
    }, 0);

    const toggleHire = (hireId: string) => {
        setSelectedIds((current) =>
            current.includes(hireId)
                ? current.filter((id) => id !== hireId)
                : [...current, hireId],
        );
        setAmounts((current) => {
            if (current[hireId] !== undefined) {
                return current;
            }
            const hire = eligibleLorryHires.find((row) => row.id === hireId);
            return {
                ...current,
                [hireId]: hire?.allocatable_amount ?? '',
            };
        });
        setPreviewLines([]);
    };

    const buildLines = () =>
        selectedIds.map((hireId) => ({
            lorry_hire_id: hireId,
            amount: amounts[hireId] ?? '0',
        }));

    const runPreview = async (): Promise<PreviewLine[]> => {
        setPreviewing(true);
        try {
            const response = await fetch(urls.bulk_preview, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement | null
                        )?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    lines: buildLines(),
                    payment_type: form.data.payment_type,
                    payment_mode: form.data.payment_mode,
                }),
            });
            if (!response.ok) {
                setPreviewLines([]);
                return [];
            }
            const payload = (await response.json()) as {
                lines?: PreviewLine[];
            };
            const lines = payload.lines ?? [];
            setPreviewLines(lines);
            return lines;
        } finally {
            setPreviewing(false);
        }
    };

    const openConfirmation = async () => {
        const latestPreview = await runPreview();
        const paymentTypeLabel =
            paymentTypeOptions.find(
                (option) => option.value === form.data.payment_type,
            )?.label ?? form.data.payment_type;
        const paymentModeLabel =
            paymentModeOptions.find(
                (option) => option.value === form.data.payment_mode,
            )?.label ?? form.data.payment_mode;
        const accountLabel =
            bankCashAccounts.find(
                (account) => account.id === form.data.bank_cash_account_id,
            )?.name ?? '—';

        const allocationRows = selectedHires.map((hire) => {
            const preview = latestPreview.find(
                (line) => line.lorry_hire_id === hire.id,
            );
            return {
                label: hire.hire_number,
                value: `${formatMoney(parseAmount(amounts[hire.id]))}${
                    preview && !preview.accepted && preview.reason
                        ? ` · ${preview.reason}`
                        : ''
                }`,
            };
        });

        setReviewFields([
            {
                label: 'Selected hires',
                value: String(selectedHires.length),
            },
            {
                label: 'Transporter',
                value: selectedHires[0]?.transporter_name ?? '—',
            },
            {
                label: 'Vehicle',
                value: selectedHires[0]?.vehicle_registration ?? '—',
            },
            {
                label: 'Payment type',
                value: paymentTypeLabel,
            },
            {
                label: 'Payment mode',
                value: paymentModeLabel,
            },
            {
                label: 'Account',
                value: form.data.payment_mode === 'bank' ? accountLabel : '—',
            },
            {
                label: 'Total payment',
                value: formatMoney(totalProposed),
            },
            ...allocationRows,
        ]);
        setConfirmOpen(true);
    };

    const submitBulk = () => {
        router.post(urls.bulk_store, {
            ...form.data,
            lines: buildLines(),
        });
    };

    return (
        <>
            <Head title="Combined / Bulk Voucher Payment" />
            <LrfPage
                title="Combined / Bulk Voucher Payment"
                subtitle="Pay multiple eligible Lorry Hires for the same transporter and vehicle in one atomic submission."
                backHref={urls.index}
                backLabel="Vouchers"
                actions={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.visit(urls.create)}
                    >
                        Single voucher
                    </Button>
                }
            >
                {errors.lines ? (
                    <p
                        role="alert"
                        className="mb-3 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {errors.lines}
                    </p>
                ) : null}

                <LrfSection
                    step={1}
                    title="Eligible Lorry Hires"
                    description="Select hires that share the same payee and vehicle. Amounts default to available payable."
                    tone="receipt"
                >
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-slate-500">
                                    <th className="px-2 py-2">Select</th>
                                    <th className="px-2 py-2">Hire</th>
                                    <th className="px-2 py-2">Bilty</th>
                                    <th className="px-2 py-2">Payee</th>
                                    <th className="px-2 py-2">Truck</th>
                                    <th className="px-2 py-2">Available</th>
                                    <th className="px-2 py-2">Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                {eligibleLorryHires.map((hire) => {
                                    const checked = selectedIds.includes(
                                        hire.id,
                                    );
                                    return (
                                        <tr
                                            key={hire.id}
                                            className="border-b border-slate-100"
                                        >
                                            <td className="px-2 py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() =>
                                                        toggleHire(hire.id)
                                                    }
                                                    aria-label={`Select ${hire.hire_number}`}
                                                />
                                            </td>
                                            <td className="px-2 py-2 font-medium">
                                                {hire.hire_number}
                                            </td>
                                            <td className="px-2 py-2">
                                                {hire.lr_number ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {hire.transporter_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {hire.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-2 tabular-nums">
                                                {formatMoney(
                                                    parseAmount(
                                                        hire.allocatable_amount,
                                                    ),
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    disabled={!checked}
                                                    value={
                                                        amounts[hire.id] ?? ''
                                                    }
                                                    onChange={(event) => {
                                                        setAmounts(
                                                            (current) => ({
                                                                ...current,
                                                                [hire.id]:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        );
                                                        setPreviewLines([]);
                                                    }}
                                                    className="h-8 w-28"
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {incompatibleSelection ? (
                        <p className="mt-3 text-sm text-amber-700">
                            Selected hires must share the same transporter and
                            vehicle. Clear mismatched rows before continuing.
                        </p>
                    ) : null}
                </LrfSection>

                <LrfSection
                    step={2}
                    title="Payment details"
                    description="Common payment type, mode and account applied to every accepted allocation."
                    tone="goods"
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="payment_type" required>
                                Payment type
                            </Label>
                            <select
                                id="payment_type"
                                required
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={form.data.payment_type}
                                onChange={(event) =>
                                    form.setData(
                                        'payment_type',
                                        event.target.value,
                                    )
                                }
                            >
                                {paymentTypeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="payment_mode" required>
                                Payment mode
                            </Label>
                            <select
                                id="payment_mode"
                                required
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={form.data.payment_mode}
                                onChange={(event) =>
                                    form.setData(
                                        'payment_mode',
                                        event.target.value,
                                    )
                                }
                            >
                                {paymentModeOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {form.data.payment_mode === 'bank' ? (
                            <div className="space-y-1">
                                <SearchableCombobox
                                    id="bulk_bank_cash_account_id"
                                    name="bank_cash_account_id"
                                    label="Bank / cash account"
                                    options={accountOptions}
                                    required
                                    value={form.data.bank_cash_account_id}
                                    onValueChange={(id) =>
                                        form.setData('bank_cash_account_id', id)
                                    }
                                    placeholder="Search account…"
                                    emptyMessage="No accounts available."
                                    addAction={
                                        canQuickCreateBankCash
                                            ? {
                                                  label: '+ Add',
                                                  onClick: () =>
                                                      setBankCashQuickCreateOpen(
                                                          true,
                                                      ),
                                              }
                                            : undefined
                                    }
                                />
                            </div>
                        ) : null}
                        <div className="space-y-1 sm:col-span-2 lg:col-span-3">
                            <Label htmlFor="remark">Remark</Label>
                            <Input
                                id="remark"
                                value={form.data.remark}
                                onChange={(event) =>
                                    form.setData('remark', event.target.value)
                                }
                            />
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <p className="text-sm text-slate-600">
                            Selected {selectedHires.length} · Total{' '}
                            <span className="font-semibold text-slate-900 tabular-nums">
                                {formatMoney(totalProposed)}
                            </span>
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                selectedHires.length === 0 ||
                                incompatibleSelection ||
                                previewing
                            }
                            onClick={() => void runPreview()}
                        >
                            {previewing ? 'Previewing…' : 'Preview allocations'}
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                selectedHires.length < 1 ||
                                incompatibleSelection ||
                                form.processing
                            }
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            onClick={() => {
                                if (!submitAllowed.current) {
                                    void openConfirmation();
                                    return;
                                }
                                submitAllowed.current = false;
                                submitBulk();
                            }}
                        >
                            Record bulk payment
                        </Button>
                    </div>

                    {previewLines.length > 0 ? (
                        <div className="mt-4 overflow-x-auto rounded-md border border-slate-200">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-slate-50 text-left text-slate-500">
                                        <th className="px-3 py-2">Hire</th>
                                        <th className="px-3 py-2">Amount</th>
                                        <th className="px-3 py-2">
                                            Allocatable
                                        </th>
                                        <th className="px-3 py-2">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {previewLines.map((line) => (
                                        <tr
                                            key={line.lorry_hire_id}
                                            className="border-b border-slate-100"
                                        >
                                            <td className="px-3 py-2">
                                                {line.hire_number || '—'}
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">
                                                ₹{line.amount}
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">
                                                ₹{line.allocatable}
                                            </td>
                                            <td className="px-3 py-2">
                                                {line.accepted
                                                    ? 'Accepted'
                                                    : (line.reason ??
                                                      'Rejected')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : null}
                </LrfSection>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Record bulk voucher payments?"
                    description="Confirmation lists each hire allocation. The transaction is all-or-nothing."
                    fields={reviewFields}
                    onConfirm={() => {
                        submitAllowed.current = true;
                        setConfirmOpen(false);
                        window.setTimeout(() => {
                            submitBulk();
                        }, 0);
                    }}
                />

                {bankCashQuickCreateOpen && urls.bank_cash_store ? (
                    <QuickCreateBankCashSheet
                        open={bankCashQuickCreateOpen}
                        onOpenChange={setBankCashQuickCreateOpen}
                        storeUrl={urls.bank_cash_store}
                        onCreated={(account: QuickCreatedBankCashAccount) => {
                            setBankCashAccounts((current) =>
                                current.some((row) => row.id === account.id)
                                    ? current
                                    : [
                                          ...current,
                                          {
                                              id: account.id,
                                              name: account.name,
                                          },
                                      ],
                            );
                            form.setData('bank_cash_account_id', account.id);
                            setBankCashQuickCreateOpen(false);
                        }}
                    />
                ) : null}
            </LrfPage>
        </>
    );
}
