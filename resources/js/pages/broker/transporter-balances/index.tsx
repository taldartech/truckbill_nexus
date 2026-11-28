import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { BookBalanceDialog } from '@/components/broker/book-balance-dialog';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDate } from '@/lib/dates';
import { formatInr } from '@/lib/money';

type TransporterOption = {
    id: string;
    name: string;
    short_name?: string | null;
    contact_number?: string | null;
};

type SummaryRow = {
    id: string;
    name: string;
    short_name: string | null;
    contact_number: string | null;
    city: string | null;
    state: string | null;
    book_balance: string | null;
    available_balance: string | null;
    last_entry_date: string | null;
};

type StatementEntry = {
    id: string;
    transaction_date: string;
    entry_date_iso?: string | null;
    entry_type: string;
    transaction_type: string;
    particulars: string;
    reference_number: string | null;
    payment_mode: string | null;
    bank_cash_account_name: string | null;
    debit: string | null;
    credit: string | null;
    amount: string | null;
    running_balance: string | null;
    hire_number: string | null;
    voucher_number: string | null;
    remark: string | null;
};

type StatementData = {
    transporter: {
        id: string;
        name: string;
        short_name: string | null;
        contact_number: string | null;
    };
    from_date: string | null;
    to_date: string | null;
    opening_balance: string | null;
    total_debit: string | null;
    total_credit: string | null;
    closing_balance: string | null;
    current_balance: string | null;
    available_balance: string | null;
    entries: StatementEntry[];
};

type Props = {
    filters: {
        transporter_party_id: string;
        from_date: string;
        to_date: string;
        q: string;
    };
    transporters: TransporterOption[];
    summaries: SummaryRow[];
    statement: StatementData | null;
    bank_cash_accounts: Array<{
        id: string;
        name: string;
        account_kind?: string;
    }>;
    today: string;
    permissions: {
        can_view_freight: boolean;
        can_manage: boolean;
        can_create_bank_cash: boolean;
    };
    urls: {
        index: string;
        parties_options?: string;
        store?: string | null;
        context?: string | null;
        bank_cash_store?: string | null;
    };
};

function moneyOrDash(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    return formatInr(value);
}

function positiveMoneyOrDash(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '—';
    }
    return formatInr(value);
}

export default function TransporterBalancesIndex({
    filters,
    transporters,
    summaries,
    statement,
    bank_cash_accounts,
    today,
    permissions,
    urls,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [transporterPartyId, setTransporterPartyId] = useState(
        filters.transporter_party_id,
    );
    const [fromDate, setFromDate] = useState(filters.from_date);
    const [toDate, setToDate] = useState(filters.to_date);
    const [query, setQuery] = useState(filters.q);
    const [modalOpen, setModalOpen] = useState(false);
    const [modalTransporter, setModalTransporter] = useState<{
        id: string;
        name: string;
        available_balance?: string | null;
    } | null>(null);

    const openRecordModal = (target?: {
        id: string;
        name: string;
        available_balance?: string | null;
    }) => {
        if (target) {
            setModalTransporter(target);
        } else if (statement) {
            setModalTransporter({
                id: statement.transporter.id,
                name: statement.transporter.name,
                available_balance: statement.available_balance,
            });
        } else {
            setModalTransporter(null);
        }
        setModalOpen(true);
    };

    const submitFilters = (next?: {
        transporter_party_id?: string;
        from_date?: string;
        to_date?: string;
        q?: string;
    }) => {
        const tp = next?.transporter_party_id ?? transporterPartyId;
        const fd = next?.from_date ?? fromDate;
        const td = next?.to_date ?? toDate;
        const search = next?.q ?? query;
        router.get(
            urls.index,
            {
                transporter_party_id: tp || undefined,
                from_date: fd || undefined,
                to_date: td || undefined,
                q: !tp && search.trim() ? search.trim() : undefined,
            },
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Transporter Book Balance" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Transporter Book Balance"
                    toolbar={
                        permissions.can_manage && urls.store ? (
                            <Button
                                type="button"
                                size="sm"
                                className="app-index-header-btn"
                                onClick={() => openRecordModal()}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Record Book Balance
                            </Button>
                        ) : null
                    }
                >
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}

                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            submitFilters();
                        }}
                    >
                        <div className="min-w-0 flex-1 space-y-1 sm:max-w-sm">
                            <SearchablePartySelect
                                id="transporter_party_id"
                                name="transporter_party_id"
                                label="Transporter"
                                options={transporters}
                                searchUrl={urls.parties_options}
                                role="transporter"
                                value={transporterPartyId}
                                onValueChange={setTransporterPartyId}
                                emptyMessage="No eligible transporters in this branch."
                            />
                        </div>
                        {transporterPartyId ? (
                            <>
                                <div className="space-y-1">
                                    <Label htmlFor="statement_from_date">
                                        From Date
                                    </Label>
                                    <Input
                                        id="statement_from_date"
                                        type="date"
                                        value={fromDate}
                                        onChange={(event) =>
                                            setFromDate(event.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="statement_to_date">
                                        To Date
                                    </Label>
                                    <Input
                                        id="statement_to_date"
                                        type="date"
                                        value={toDate}
                                        onChange={(event) =>
                                            setToDate(event.target.value)
                                        }
                                    />
                                </div>
                            </>
                        ) : (
                            <div className="space-y-1">
                                <Label htmlFor="summary_q">Search</Label>
                                <Input
                                    id="summary_q"
                                    value={query}
                                    placeholder="Transporter name or mobile"
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    className="min-w-[14rem]"
                                />
                            </div>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            View statement
                        </Button>
                        {statement ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setTransporterPartyId('');
                                    setFromDate('');
                                    setToDate('');
                                    submitFilters({
                                        transporter_party_id: '',
                                        from_date: '',
                                        to_date: '',
                                    });
                                }}
                            >
                                All transporters
                            </Button>
                        ) : null}
                    </AppIndexFilters>

                    {statement ? (
                        <>
                            <div className="grid gap-3 border-b border-[#e5e7eb] bg-[#f0fdf4] px-4 py-3 sm:grid-cols-2 lg:grid-cols-5">
                                <div>
                                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        Opening Balance
                                    </p>
                                    <p className="text-lg font-semibold text-slate-900 tabular-nums">
                                        {moneyOrDash(statement.opening_balance)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        Total Debit
                                    </p>
                                    <p className="text-lg font-semibold text-emerald-700 tabular-nums">
                                        {moneyOrDash(statement.total_debit)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        Total Credit
                                    </p>
                                    <p className="text-lg font-semibold text-amber-700 tabular-nums">
                                        {moneyOrDash(statement.total_credit)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        Closing Balance
                                    </p>
                                    <p className="text-lg font-bold text-slate-900 tabular-nums">
                                        {moneyOrDash(statement.closing_balance)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        Running balance
                                    </p>
                                    <p
                                        className="text-xl font-bold text-emerald-900 tabular-nums"
                                        data-testid="statement-running-balance"
                                    >
                                        {moneyOrDash(statement.current_balance)}
                                    </p>
                                </div>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="app-index-table min-w-full">
                                    <thead>
                                        <tr>
                                            <th>Transaction Date</th>
                                            <th>Transaction Type</th>
                                            <th>Particulars</th>
                                            <th>Reference Number</th>
                                            <th className="text-end">Debit</th>
                                            <th className="text-end">Credit</th>
                                            <th className="text-end">
                                                Running Balance
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr className="bg-slate-50/70 font-medium">
                                            <td>
                                                {statement.from_date
                                                    ? formatDisplayDate(
                                                          statement.from_date,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td>Opening Balance</td>
                                            <td className="text-slate-600">
                                                Balance brought forward
                                            </td>
                                            <td>—</td>
                                            <td className="text-end tabular-nums">
                                                —
                                            </td>
                                            <td className="text-end tabular-nums">
                                                —
                                            </td>
                                            <td className="text-end font-semibold tabular-nums">
                                                {moneyOrDash(
                                                    statement.opening_balance,
                                                )}
                                            </td>
                                        </tr>

                                        {statement.entries.length === 0 ? (
                                            <tr>
                                                <td colSpan={7}>
                                                    <p className="app-index-empty">
                                                        No book balance
                                                        transactions in this
                                                        period.
                                                    </p>
                                                </td>
                                            </tr>
                                        ) : (
                                            statement.entries.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="whitespace-nowrap">
                                                        {row.entry_date_iso
                                                            ? formatDisplayDate(
                                                                  row.entry_date_iso,
                                                              )
                                                            : row.transaction_date ||
                                                              '—'}
                                                    </td>
                                                    <td className="font-medium whitespace-nowrap">
                                                        {row.transaction_type}
                                                    </td>
                                                    <td className="max-w-[18rem] whitespace-normal">
                                                        <div>
                                                            {row.particulars}
                                                        </div>
                                                        {row.remark ? (
                                                            <div className="text-xs text-slate-500">
                                                                {row.remark}
                                                            </div>
                                                        ) : null}
                                                    </td>
                                                    <td className="font-mono text-xs text-slate-600">
                                                        {row.reference_number ??
                                                            '—'}
                                                    </td>
                                                    <td className="text-end font-medium text-emerald-700 tabular-nums">
                                                        {positiveMoneyOrDash(
                                                            row.debit,
                                                        )}
                                                    </td>
                                                    <td className="text-end font-medium text-amber-700 tabular-nums">
                                                        {positiveMoneyOrDash(
                                                            row.credit,
                                                        )}
                                                    </td>
                                                    <td className="text-end font-semibold text-slate-900 tabular-nums">
                                                        {moneyOrDash(
                                                            row.running_balance,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))
                                        )}

                                        <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                                            <td colSpan={4}>Closing Balance</td>
                                            <td className="text-end text-emerald-800 tabular-nums">
                                                {moneyOrDash(
                                                    statement.total_debit,
                                                )}
                                            </td>
                                            <td className="text-end text-amber-800 tabular-nums">
                                                {moneyOrDash(
                                                    statement.total_credit,
                                                )}
                                            </td>
                                            <td className="text-end text-slate-900 tabular-nums">
                                                {moneyOrDash(
                                                    statement.closing_balance,
                                                )}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="app-index-table min-w-full">
                                <thead>
                                    <tr>
                                        <th>Transporter</th>
                                        <th>Short Name</th>
                                        <th>Mobile</th>
                                        <th>Location</th>
                                        <th className="text-end">
                                            Available Book Balance
                                        </th>
                                        <th>Last Entry</th>
                                        <th className="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {summaries.length === 0 ? (
                                        <tr>
                                            <td colSpan={7}>
                                                <p className="app-index-empty">
                                                    No eligible transporters
                                                    found in this branch.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        summaries.map((row) => (
                                            <tr key={row.id}>
                                                <td className="font-medium">
                                                    <Link
                                                        href={`${urls.index}?transporter_party_id=${row.id}`}
                                                        className="hover:text-[#0f766e] hover:underline"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                </td>
                                                <td className="text-slate-600">
                                                    {row.short_name ?? '—'}
                                                </td>
                                                <td className="text-slate-600 tabular-nums">
                                                    {row.contact_number ?? '—'}
                                                </td>
                                                <td className="text-slate-600">
                                                    {[row.city, row.state]
                                                        .filter(Boolean)
                                                        .join(', ') || '—'}
                                                </td>
                                                <td className="text-end font-semibold text-emerald-900 tabular-nums">
                                                    {moneyOrDash(
                                                        row.available_balance,
                                                    )}
                                                </td>
                                                <td className="text-slate-600">
                                                    {row.last_entry_date || '—'}
                                                </td>
                                                <td>
                                                    <div className="flex items-center justify-end gap-1">
                                                        {permissions.can_manage &&
                                                        urls.store ? (
                                                            <AppIconAction
                                                                icon={Wallet}
                                                                label="Book Balance"
                                                                type="button"
                                                                variant="primary"
                                                                onClick={() =>
                                                                    openRecordModal(
                                                                        {
                                                                            id: row.id,
                                                                            name: row.name,
                                                                            available_balance:
                                                                                row.available_balance,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        ) : null}
                                                        <AppIconAction
                                                            icon={BookOpen}
                                                            label="View Statement"
                                                            href={`${urls.index}?transporter_party_id=${row.id}`}
                                                            variant="info"
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </AppIndexListCard>
            </AppIndexPage>

            {permissions.can_manage && urls.store ? (
                <BookBalanceDialog
                    open={modalOpen}
                    onOpenChange={setModalOpen}
                    transporter={modalTransporter}
                    transporters={transporters}
                    partiesOptionsUrl={urls.parties_options}
                    contextUrl={urls.context}
                    storeUrl={urls.store}
                    bankCashAccounts={bank_cash_accounts}
                    bankCashStoreUrl={urls.bank_cash_store}
                    canCreateBankCash={permissions.can_create_bank_cash}
                    today={today}
                    onRecorded={(transporterId) => {
                        setTransporterPartyId(transporterId);
                        router.get(
                            urls.index,
                            {
                                transporter_party_id: transporterId,
                                from_date: fromDate || undefined,
                                to_date: toDate || undefined,
                            },
                            { preserveState: false },
                        );
                    }}
                />
            ) : null}
        </>
    );
}
