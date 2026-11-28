import { useForm, useHttp } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import { QuickCreateBankCashSheet } from '@/components/masters/quick-create-bank-cash-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatInr, formatInrPlain } from '@/lib/money';

type BankCashOption = {
    id: string;
    name: string;
    account_kind?: string;
};

type RecentBookBalanceEntry = {
    id: string;
    transaction_date: string;
    transaction_type: string;
    particulars: string;
    reference_number: string | null;
    debit: string | null;
    credit: string | null;
    amount: string | null;
};

type BookBalanceContextPayload = {
    transporter: {
        id: string;
        name: string;
        short_name: string | null;
    };
    available_balance: string | null;
    running_balance: string | null;
    recent_entries: RecentBookBalanceEntry[];
    bank_cash_accounts: BankCashOption[];
    today: string;
    bank_cash_store_url: string | null;
    permissions: {
        can_manage: boolean;
        can_view_freight: boolean;
        can_create_bank_cash: boolean;
    };
};

type TransactionType =
    | 'advance'
    | 'recovery'
    | 'adjustment'
    | 'opening_balance';

type BookBalanceForm = {
    transporter_party_id: string;
    transaction_type: TransactionType;
    adjustment_direction: 'add' | 'deduct';
    amount: string;
    entry_date: string;
    payment_mode: '' | 'cash' | 'bank';
    bank_cash_account_id: string;
    reference_number: string;
    remark: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    transporter: {
        id: string;
        name: string;
        available_balance?: string | null;
    } | null;
    transporters?: Array<{ id: string; name: string }>;
    partiesOptionsUrl?: string;
    contextUrl?: string | null;
    storeUrl: string;
    bankCashAccounts: BankCashOption[];
    bankCashStoreUrl?: string | null;
    canCreateBankCash?: boolean;
    today: string;
    onRecorded?: (transporterId: string) => void;
};

const TRANSACTION_TYPE_OPTIONS: Array<{
    value: TransactionType;
    label: string;
    hint: string;
}> = [
    {
        value: 'advance',
        label: 'Advance Payment',
        hint: 'Adds to Transporter Book Balance (independent of Lorry Hire).',
    },
    {
        value: 'recovery',
        label: 'Refund / Recovery',
        hint: 'Recovers balance back from the transporter (capped by available balance).',
    },
    {
        value: 'adjustment',
        label: 'Balance Adjustment',
        hint: 'Add or deduct Book Balance with a mandatory audit remark.',
    },
    {
        value: 'opening_balance',
        label: 'Opening Balance',
        hint: 'Record brought-forward Book Balance.',
    },
];

function toNumber(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value.trim() === '') {
        return null;
    }
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function formatAmount(value: string | null | undefined): string {
    return value === null || value === undefined ? '—' : formatInr(value);
}

/**
 * Shared Book Balance transaction modal used from Party Master Register,
 * Party Details, and Transporter Book Balance statement/overview.
 *
 * Amount ALWAYS opens blank and resets cleanly on reopen.
 */
export function BookBalanceDialog({
    open,
    onOpenChange,
    transporter,
    transporters = [],
    partiesOptionsUrl,
    contextUrl,
    storeUrl,
    bankCashAccounts,
    bankCashStoreUrl,
    canCreateBankCash = false,
    today,
    onRecorded,
}: Props) {
    const contextRequest = useHttp<
        Record<string, never>,
        BookBalanceContextPayload
    >({});
    const [context, setContext] = useState<BookBalanceContextPayload | null>(
        null,
    );
    const [loadError, setLoadError] = useState<string | null>(null);
    const [accounts, setAccounts] =
        useState<BankCashOption[]>(bankCashAccounts);
    const [quickBankOpen, setQuickBankOpen] = useState(false);
    const [showClientErrors, setShowClientErrors] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const form = useForm<BookBalanceForm>({
        transporter_party_id: transporter?.id ?? '',
        transaction_type: 'advance',
        adjustment_direction: 'add',
        amount: '',
        entry_date: today,
        payment_mode: 'cash',
        bank_cash_account_id: '',
        reference_number: '',
        remark: '',
    });

    const selectedTransporterId =
        transporter?.id ?? form.data.transporter_party_id;

    useEffect(() => {
        if (!open) {
            setContext(null);
            setLoadError(null);
            setShowClientErrors(false);
            setConfirmOpen(false);
            form.clearErrors();
            form.setData({
                transporter_party_id: '',
                transaction_type: 'advance',
                adjustment_direction: 'add',
                amount: '',
                entry_date: today,
                payment_mode: 'cash',
                bank_cash_account_id: '',
                reference_number: '',
                remark: '',
            });

            return;
        }

        setAccounts(bankCashAccounts);
        setLoadError(null);
        setShowClientErrors(false);
        setConfirmOpen(false);
        form.clearErrors();
        form.setData({
            transporter_party_id: transporter?.id ?? '',
            transaction_type: 'advance',
            adjustment_direction: 'add',
            amount: '',
            entry_date: today,
            payment_mode: 'cash',
            bank_cash_account_id: '',
            reference_number: '',
            remark: '',
        });
        // Reset cleanly whenever dialog opens or closes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, transporter?.id, today]);

    useEffect(() => {
        if (!open || !contextUrl || !selectedTransporterId) {
            setContext(null);

            return;
        }

        setLoadError(null);
        const query = new URLSearchParams({
            transporter_party_id: selectedTransporterId,
        });
        void contextRequest.get(`${contextUrl}?${query.toString()}`, {
            onSuccess: (payload) => {
                setContext(payload);
                if (payload.bank_cash_accounts.length > 0) {
                    setAccounts(payload.bank_cash_accounts);
                }
            },
            onError: () => {
                setLoadError('Unable to load transporter Book Balance.');
            },
            onHttpException: () => {
                setLoadError('Unable to load transporter Book Balance.');

                return false;
            },
            onNetworkError: () => {
                setLoadError('Network error while loading Book Balance.');

                return false;
            },
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, contextUrl, selectedTransporterId]);

    const availableBalanceText =
        context?.available_balance ?? transporter?.available_balance ?? null;
    const availableBalance = toNumber(availableBalanceText);

    const activeTransporterName =
        transporter?.name ??
        context?.transporter?.name ??
        transporters.find((item) => item.id === selectedTransporterId)?.name ??
        '';

    const txType = form.data.transaction_type;
    const requiresPaymentMode = txType === 'advance' || txType === 'recovery';
    const showsPaymentMode = txType !== 'opening_balance';
    const isDeduction =
        txType === 'recovery' ||
        (txType === 'adjustment' &&
            form.data.adjustment_direction === 'deduct');

    const clientErrors = useMemo(() => {
        const errs: Record<string, string> = {};
        if (!form.data.transporter_party_id) {
            errs.transporter_party_id = 'Select a transporter.';
        }
        const amount = toNumber(form.data.amount);
        if (amount === null) {
            errs.amount = 'Enter the transaction amount.';
        } else if (amount <= 0) {
            errs.amount = 'Amount must be greater than zero.';
        } else if (
            isDeduction &&
            availableBalance !== null &&
            amount > availableBalance + 0.0001
        ) {
            errs.amount = `Amount cannot exceed the available Book Balance of ${formatInrPlain(availableBalance)}.`;
        }
        if (form.data.entry_date === '') {
            errs.entry_date = 'Select the transaction date.';
        }
        if (
            requiresPaymentMode &&
            form.data.payment_mode !== 'cash' &&
            form.data.payment_mode !== 'bank'
        ) {
            errs.payment_mode = 'Select Cash or Bank.';
        }
        if (
            showsPaymentMode &&
            form.data.payment_mode === 'bank' &&
            form.data.bank_cash_account_id === ''
        ) {
            errs.bank_cash_account_id = 'Select a bank account.';
        }
        if (txType === 'adjustment' && form.data.remark.trim() === '') {
            errs.remark = 'Remark is required for a balance adjustment.';
        }

        return errs;
    }, [
        form.data,
        isDeduction,
        availableBalance,
        requiresPaymentMode,
        showsPaymentMode,
        txType,
    ]);

    const errorFor = (key: keyof BookBalanceForm): string | undefined => {
        const serverErrors = form.errors as Record<string, string | undefined>;
        const immediate = key === 'amount' && form.data.amount !== '';

        return (
            serverErrors[key] ??
            (showClientErrors || immediate ? clientErrors[key] : undefined)
        );
    };

    const hasClientErrors = Object.keys(clientErrors).length > 0;
    const bankOptions: ComboboxOption[] = accounts
        .filter((account) => account.account_kind !== 'cash')
        .map((account) => ({
            id: account.id,
            label: account.name,
        }));
    const allBankOptions: ComboboxOption[] =
        bankOptions.length > 0
            ? bankOptions
            : accounts.map((account) => ({
                  id: account.id,
                  label: account.name,
              }));

    const effectiveBankStoreUrl =
        context?.bank_cash_store_url ?? bankCashStoreUrl ?? null;
    const effectiveCanCreateBank =
        (context?.permissions?.can_create_bank_cash ?? canCreateBankCash) &&
        Boolean(effectiveBankStoreUrl);

    const handleTransactionTypeChange = (nextType: TransactionType) => {
        form.setData((current) => ({
            ...current,
            transaction_type: nextType,
            payment_mode:
                nextType === 'opening_balance'
                    ? ''
                    : nextType === 'adjustment'
                      ? current.payment_mode
                      : current.payment_mode === 'bank' ||
                          current.payment_mode === 'cash'
                        ? current.payment_mode
                        : 'cash',
            bank_cash_account_id:
                nextType === 'opening_balance'
                    ? ''
                    : current.bank_cash_account_id,
        }));
        form.clearErrors();
    };

    const review = () => {
        setShowClientErrors(true);
        if (!hasClientErrors) {
            setConfirmOpen(true);
        }
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            adjustment_direction:
                data.transaction_type === 'adjustment'
                    ? data.adjustment_direction
                    : null,
            payment_mode:
                data.transaction_type === 'opening_balance'
                    ? null
                    : data.payment_mode || null,
            bank_cash_account_id:
                data.transaction_type !== 'opening_balance' &&
                data.payment_mode === 'bank'
                    ? data.bank_cash_account_id || null
                    : null,
        }));
        form.post(storeUrl, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const recordedFor = form.data.transporter_party_id;
                setConfirmOpen(false);
                onOpenChange(false);
                onRecorded?.(recordedFor);
            },
            onError: () => setConfirmOpen(false),
        });
    };

    const selectedTypeMeta = TRANSACTION_TYPE_OPTIONS.find(
        (item) => item.value === txType,
    );
    const activeBankLabel =
        accounts.find((item) => item.id === form.data.bank_cash_account_id)
            ?.name ?? '—';

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
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            Book Balance
                            {activeTransporterName
                                ? ` — ${activeTransporterName}`
                                : ''}
                        </DialogTitle>
                        <DialogDescription>
                            Record an independent transporter advance, refund or
                            recovery, balance adjustment, or opening balance.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        id="book-balance-transaction-form"
                        className="space-y-4 text-sm"
                        noValidate
                        onSubmit={(event) => {
                            event.preventDefault();
                            review();
                        }}
                    >
                        {loadError ? (
                            <p
                                role="alert"
                                className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                            >
                                {loadError}
                            </p>
                        ) : null}

                        {transporter ? (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-3">
                                <div>
                                    <p className="text-xs font-medium tracking-wide text-emerald-800 uppercase">
                                        Transporter
                                    </p>
                                    <p className="text-sm font-semibold text-slate-900">
                                        {transporter.name}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p className="text-xs font-medium tracking-wide text-emerald-800 uppercase">
                                        Available Book Balance
                                    </p>
                                    <p
                                        className="text-xl font-bold text-emerald-950 tabular-nums"
                                        data-testid="book-balance-available"
                                    >
                                        {formatAmount(availableBalanceText)}
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <SearchablePartySelect
                                    id="book_balance_transporter_party_id"
                                    name="transporter_party_id"
                                    label="Transporter"
                                    required
                                    options={transporters}
                                    searchUrl={partiesOptionsUrl}
                                    role="transporter"
                                    value={form.data.transporter_party_id}
                                    onValueChange={(nextId) => {
                                        form.setData(
                                            'transporter_party_id',
                                            nextId,
                                        );
                                        form.clearErrors(
                                            'transporter_party_id',
                                        );
                                    }}
                                    emptyMessage="Search eligible transporters…"
                                    error={errorFor('transporter_party_id')}
                                />
                                {selectedTransporterId ? (
                                    <div className="flex items-center justify-between rounded-md border border-emerald-200 bg-emerald-50/70 px-3 py-2">
                                        <span className="text-xs font-medium text-emerald-800 uppercase">
                                            Available Book Balance
                                        </span>
                                        <span
                                            className="text-base font-bold text-emerald-950 tabular-nums"
                                            data-testid="book-balance-available"
                                        >
                                            {formatAmount(availableBalanceText)}
                                        </span>
                                    </div>
                                ) : null}
                            </div>
                        )}

                        <div className="grid gap-3 md:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="bb_transaction_type" required>
                                    Transaction Type
                                </Label>
                                <select
                                    id="bb_transaction_type"
                                    value={txType}
                                    onChange={(event) =>
                                        handleTransactionTypeChange(
                                            event.target
                                                .value as TransactionType,
                                        )
                                    }
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {TRANSACTION_TYPE_OPTIONS.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                {selectedTypeMeta ? (
                                    <p className="text-xs text-slate-500">
                                        {selectedTypeMeta.hint}
                                    </p>
                                ) : null}
                                {errorFor('transaction_type') ? (
                                    <p className="text-xs text-destructive">
                                        {errorFor('transaction_type')}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="bb_entry_date" required>
                                    Transaction Date
                                </Label>
                                <Input
                                    id="bb_entry_date"
                                    type="date"
                                    max={context?.today ?? today}
                                    value={form.data.entry_date}
                                    onChange={(event) => {
                                        form.setData(
                                            'entry_date',
                                            event.target.value,
                                        );
                                        form.clearErrors('entry_date');
                                    }}
                                    required
                                />
                                {errorFor('entry_date') ? (
                                    <p className="text-xs text-destructive">
                                        {errorFor('entry_date')}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        {txType === 'adjustment' ? (
                            <div className="space-y-1">
                                <Label required>Adjustment Direction</Label>
                                <div className="flex flex-wrap gap-2">
                                    {(
                                        [
                                            {
                                                value: 'add',
                                                label: 'Add to Book Balance (+)',
                                            },
                                            {
                                                value: 'deduct',
                                                label: 'Deduct from Book Balance (−)',
                                            },
                                        ] as const
                                    ).map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() => {
                                                form.setData(
                                                    'adjustment_direction',
                                                    option.value,
                                                );
                                                form.clearErrors(
                                                    'adjustment_direction',
                                                );
                                            }}
                                            className={`rounded-md border px-3 py-1.5 text-xs font-medium ${
                                                form.data
                                                    .adjustment_direction ===
                                                option.value
                                                    ? 'border-[#2FB36F] bg-[#2FB36F]/10 text-[#135E37]'
                                                    : 'border-input bg-background text-slate-700 hover:bg-slate-50'
                                            }`}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        <div className="grid gap-3 md:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="bb_amount" required>
                                    Amount
                                </Label>
                                <Input
                                    id="bb_amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    placeholder="Enter amount"
                                    value={form.data.amount}
                                    onChange={(event) => {
                                        form.setData(
                                            'amount',
                                            event.target.value,
                                        );
                                        form.clearErrors('amount');
                                    }}
                                    required
                                />
                                {errorFor('amount') ? (
                                    <p className="text-xs text-destructive">
                                        {errorFor('amount')}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="bb_reference_number">
                                    Reference Number
                                </Label>
                                <Input
                                    id="bb_reference_number"
                                    maxLength={64}
                                    placeholder="UTR / Cheque / Slip No"
                                    value={form.data.reference_number}
                                    onChange={(event) =>
                                        form.setData(
                                            'reference_number',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        {showsPaymentMode ? (
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="bb_payment_mode"
                                        required={requiresPaymentMode}
                                    >
                                        Payment Mode
                                    </Label>
                                    <select
                                        id="bb_payment_mode"
                                        value={form.data.payment_mode}
                                        onChange={(event) => {
                                            const mode = event.target.value as
                                                | ''
                                                | 'cash'
                                                | 'bank';
                                            form.setData((current) => ({
                                                ...current,
                                                payment_mode: mode,
                                                bank_cash_account_id:
                                                    mode === 'bank'
                                                        ? current.bank_cash_account_id
                                                        : '',
                                            }));
                                            form.clearErrors('payment_mode');
                                            form.clearErrors(
                                                'bank_cash_account_id',
                                            );
                                        }}
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        {txType === 'adjustment' ? (
                                            <option value="">
                                                Non-cash adjustment
                                            </option>
                                        ) : null}
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank</option>
                                    </select>
                                    {errorFor('payment_mode') ? (
                                        <p className="text-xs text-destructive">
                                            {errorFor('payment_mode')}
                                        </p>
                                    ) : null}
                                </div>

                                {form.data.payment_mode === 'bank' ? (
                                    <div>
                                        <SearchableCombobox
                                            id="bb_bank_cash_account_id"
                                            name="bank_cash_account_id"
                                            label="Bank Account"
                                            required
                                            options={allBankOptions}
                                            value={
                                                form.data.bank_cash_account_id
                                            }
                                            onValueChange={(next) => {
                                                form.setData(
                                                    'bank_cash_account_id',
                                                    next,
                                                );
                                                form.clearErrors(
                                                    'bank_cash_account_id',
                                                );
                                            }}
                                            placeholder="Select bank account…"
                                            emptyMessage="No active bank accounts."
                                            error={errorFor(
                                                'bank_cash_account_id',
                                            )}
                                            addAction={
                                                effectiveCanCreateBank
                                                    ? {
                                                          label: '+ Add',
                                                          onClick: () =>
                                                              setQuickBankOpen(
                                                                  true,
                                                              ),
                                                      }
                                                    : undefined
                                            }
                                        />
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        <div className="space-y-1">
                            <Label
                                htmlFor="bb_remark"
                                required={txType === 'adjustment'}
                            >
                                Remark
                            </Label>
                            <Input
                                id="bb_remark"
                                maxLength={1000}
                                placeholder={
                                    txType === 'adjustment'
                                        ? 'Reason for balance adjustment'
                                        : 'Optional note or particulars'
                                }
                                value={form.data.remark}
                                onChange={(event) => {
                                    form.setData('remark', event.target.value);
                                    form.clearErrors('remark');
                                }}
                                required={txType === 'adjustment'}
                            />
                            {errorFor('remark') ? (
                                <p className="text-xs text-destructive">
                                    {errorFor('remark')}
                                </p>
                            ) : null}
                        </div>

                        {context && context.recent_entries.length > 0 ? (
                            <details className="rounded-md border border-slate-200">
                                <summary className="cursor-pointer bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                                    Recent Book Balance Transactions (
                                    {context.recent_entries.length})
                                </summary>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-t border-slate-200 text-left text-slate-500">
                                                <th className="px-3 py-1">
                                                    Date
                                                </th>
                                                <th className="px-3 py-1">
                                                    Type
                                                </th>
                                                <th className="px-3 py-1">
                                                    Particulars
                                                </th>
                                                <th className="px-3 py-1 text-end">
                                                    Debit
                                                </th>
                                                <th className="px-3 py-1 text-end">
                                                    Credit
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {context.recent_entries.map(
                                                (entry) => (
                                                    <tr
                                                        key={entry.id}
                                                        className="border-t border-slate-100"
                                                    >
                                                        <td className="px-3 py-1">
                                                            {
                                                                entry.transaction_date
                                                            }
                                                        </td>
                                                        <td className="px-3 py-1">
                                                            {
                                                                entry.transaction_type
                                                            }
                                                        </td>
                                                        <td className="max-w-[14rem] truncate px-3 py-1 text-slate-600">
                                                            {entry.particulars}
                                                        </td>
                                                        <td className="px-3 py-1 text-end text-emerald-700 tabular-nums">
                                                            {entry.debit &&
                                                            Number(
                                                                entry.debit,
                                                            ) > 0
                                                                ? formatAmount(
                                                                      entry.debit,
                                                                  )
                                                                : '—'}
                                                        </td>
                                                        <td className="px-3 py-1 text-end text-amber-700 tabular-nums">
                                                            {entry.credit &&
                                                            Number(
                                                                entry.credit,
                                                            ) > 0
                                                                ? formatAmount(
                                                                      entry.credit,
                                                                  )
                                                                : '—'}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        ) : null}
                    </form>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="book-balance-transaction-form"
                            disabled={form.processing}
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Review transaction
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="create"
                title="Confirm Book Balance transaction?"
                description="Review the entry below. Book Balance changes are preserved in the immutable ledger."
                processing={form.processing}
                confirmLabel="Save transaction"
                onConfirm={submit}
                sections={[
                    {
                        title: 'Book Balance Entry',
                        fields: [
                            {
                                label: 'Transporter',
                                value: activeTransporterName || '—',
                            },
                            {
                                label: 'Transaction Type',
                                value:
                                    txType === 'adjustment'
                                        ? `${selectedTypeMeta?.label ?? 'Adjustment'} (${form.data.adjustment_direction === 'deduct' ? 'Deduct −' : 'Add +'})`
                                        : (selectedTypeMeta?.label ?? txType),
                            },
                            {
                                label: 'Amount',
                                value: formatInrPlain(
                                    Number(form.data.amount || 0),
                                ),
                            },
                            {
                                label: 'Transaction Date',
                                value: form.data.entry_date,
                            },
                            ...(showsPaymentMode
                                ? [
                                      {
                                          label: 'Payment Mode',
                                          value:
                                              form.data.payment_mode === 'bank'
                                                  ? `Bank (${activeBankLabel})`
                                                  : form.data.payment_mode ===
                                                      'cash'
                                                    ? 'Cash'
                                                    : 'Non-cash adjustment',
                                      },
                                  ]
                                : []),
                            ...(form.data.reference_number.trim() !== ''
                                ? [
                                      {
                                          label: 'Reference Number',
                                          value: form.data.reference_number.trim(),
                                      },
                                  ]
                                : []),
                            ...(form.data.remark.trim() !== ''
                                ? [
                                      {
                                          label: 'Remark',
                                          value: form.data.remark.trim(),
                                      },
                                  ]
                                : []),
                        ],
                    },
                ]}
            />

            {effectiveCanCreateBank && effectiveBankStoreUrl ? (
                <QuickCreateBankCashSheet
                    open={quickBankOpen}
                    onOpenChange={setQuickBankOpen}
                    storeUrl={effectiveBankStoreUrl}
                    onCreated={(created) => {
                        setAccounts((current) => [...current, created]);
                        form.setData('bank_cash_account_id', created.id);
                    }}
                />
            ) : null}
        </>
    );
}
