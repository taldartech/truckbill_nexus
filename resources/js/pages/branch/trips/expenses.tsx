import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FileText, Fuel, Route, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    QuickCreateBankCashSheet,
    type QuickCreatedBankCashAccount,
} from '@/components/masters/quick-create-bank-cash-sheet';
import {
    QuickCreateCatalogueSheet,
    type QuickCreatedCatalogueItem,
} from '@/components/masters/quick-create-catalogue-sheet';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import {
    TripDetailCard,
    TripDetailLayout,
    TripDetailPage,
    TripDieselCard,
    TripExpensesTable,
    TripFinancialSummary,
    TripInfoGrid,
    TripInfoItem,
    type TripProfitProps,
} from '@/components/trip-detail';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';

type ExpenseRow = {
    id: string;
    expense_date: string | null;
    category_name: string | null;
    amount: string;
    is_diesel: boolean;
    diesel_litres: string | null;
    diesel_rate_per_litre?: string | null;
    diesel_odometer?: string | null;
    planned_diesel_quantity?: string | null;
    planned_diesel_rate?: string | null;
    planned_diesel_amount?: string | null;
    remarks: string | null;
    payment_mode?: string | null;
    vendor_party_id?: string | null;
    vendor_name?: string | null;
    is_credit_payable?: boolean;
    settled_at?: string | null;
};

type TripSummary = {
    id: string;
    status: string;
    lorry_receipt_id: string;
    lr_number: string | null;
    customer_name: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    vehicle_registration: string | null;
    driver_party_id?: string | null;
    driver_name: string | null;
    starting_odometer: string | null;
    ending_odometer: string | null;
    trip_distance: string | null;
    lr_freight_total: string | null;
};

type FormOptions = {
    expense_categories: Array<{ id: string; name: string }>;
    vendors?: Array<{ id: string; name: string }>;
    drivers?: Array<{ id: string; name: string }>;
    payment_modes: Array<{ id: string; name: string }>;
    bank_cash_accounts: Array<{ id: string; name: string }>;
};

type DriverAdvanceSummary = {
    entries: Array<{
        id: string;
        entry_date: string;
        driver_party_id: string;
        driver_name: string | null;
        entry_type: string;
        amount: string | null;
        payment_mode: string;
        reference: string | null;
        remark: string | null;
    }>;
    driver_balances: Array<{
        driver_party_id: string;
        driver_name: string;
        advances: string | null;
        settlements: string | null;
        recoveries: string | null;
        net_balance: string | null;
    }>;
    total_outstanding: string | null;
};

type VendorPayablesSummary = {
    unpaid_expenses: Array<{
        id: string;
        trip_id: string;
        trip_number: string | null;
        vendor_party_id: string;
        vendor_name: string | null;
        category_name: string | null;
        expense_date: string;
        amount: string | null;
        paid_amount: string | null;
        remaining_amount: string | null;
    }>;
    vendor_balances: Array<{
        vendor_party_id: string;
        vendor_name: string;
        billed: string | null;
        paid: string | null;
        balance_due: string | null;
    }>;
    total_balance_due: string | null;
};

type Props = {
    trip: TripSummary;
    expenses: ExpenseRow[];
    profit: TripProfitProps;
    driverAdvances?: DriverAdvanceSummary;
    vendorPayables?: VendorPayablesSummary;
    formOptions: FormOptions;
    permissions: {
        can_edit: boolean;
        can_view_freight: boolean;
        can_quick_create_catalogues?: boolean;
        can_create_bank_cash?: boolean;
    };
    urls: {
        show: string;
        multi_point?: string;
        lr_show: string;
        index: string;
        add_expense: string;
        remove_expense: string;
        driver_advance_store?: string;
        vendor_payment_store?: string;
        expense_categories_store?: string;
        bank_cash_store?: string;
        documents: string;
    };
    documentDrawer?: DocumentDrawerProps | null;
};

function statusLabel(status: string): string {
    switch (status) {
        case 'pending':
            return 'Pending';
        case 'in_progress':
            return 'In progress';
        case 'completed':
            return 'Completed';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

function expenseTableHeaders(mutable: boolean): string[] {
    const headers = [
        'Date',
        'Category',
        'Odometer',
        'Litres',
        'Rate',
        'Amount',
        'Notes',
    ];
    if (mutable) {
        headers.push('');
    }
    return headers;
}

export default function TripExpensesWorkspace({
    trip,
    expenses,
    profit,
    driverAdvances,
    vendorPayables,
    formOptions,
    permissions,
    urls,
    documentDrawer = null,
}: Props) {
    const page = usePage<{
        flash?: { success?: string; info?: string };
        errors?: Record<string, string>;
    }>();
    const flash = page.props.flash;
    const errors = page.props.errors;
    const [docsOpen, setDocsOpen] = useState(false);
    const [isDiesel, setIsDiesel] = useState(false);
    const [isCreditPayable, setIsCreditPayable] = useState(false);
    const [vendorPartyId, setVendorPartyId] = useState('');
    const [expenseCategories, setExpenseCategories] = useState(
        () => formOptions.expense_categories,
    );
    const [bankCashAccounts, setBankCashAccounts] = useState(
        () => formOptions.bank_cash_accounts,
    );
    const [paidFromBankCashAccountId, setPaidFromBankCashAccountId] =
        useState('');
    const [expenseCategoryId, setExpenseCategoryId] = useState('');
    const [expenseCategoryQuickCreateOpen, setExpenseCategoryQuickCreateOpen] =
        useState(false);
    const [bankCashQuickCreateOpen, setBankCashQuickCreateOpen] =
        useState(false);
    const canQuickCreateCatalogues =
        permissions.can_quick_create_catalogues === true;
    const canQuickCreateBankCash =
        permissions.can_create_bank_cash === true &&
        Boolean(urls.bank_cash_store);

    const mutable = permissions.can_edit && trip.status !== 'cancelled';
    const dieselLitres = useMemo(
        () =>
            expenses
                .filter((row) => row.is_diesel && row.diesel_litres)
                .reduce((sum, row) => sum + Number(row.diesel_litres), 0)
                .toFixed(3),
        [expenses],
    );

    const routeLabel = [trip.from_station_name, trip.to_station_name]
        .filter(Boolean)
        .join(' → ');

    return (
        <>
            <Head title={`Manage Expenses — ${trip.lr_number ?? 'Trip'}`} />
            <LrfPage
                title="Manage Expenses"
                subtitle={
                    trip.lr_number
                        ? `Bilty ${trip.lr_number} · Trip expenses, diesel and profitability`
                        : 'Trip expenses, diesel and profitability'
                }
                backHref={urls.show}
                backLabel="Trip workspace"
                actions={
                    <>
                        <AppIconAction
                            icon={Wallet}
                            label="Bilty Register"
                            href={urls.index}
                            variant="outline"
                        />
                        {urls.multi_point ? (
                            <AppIconAction
                                icon={Route}
                                label="Multi-point route"
                                href={urls.multi_point}
                                variant="outline"
                            />
                        ) : null}
                        {documentDrawer ? (
                            <AppIconAction
                                icon={FileText}
                                label="Documents"
                                variant="outline"
                                onClick={() => setDocsOpen(true)}
                            />
                        ) : null}
                    </>
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {flash?.info ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.info}
                    </p>
                ) : null}

                <TripDetailPage>
                    <TripDetailLayout
                        main={
                            <>
                                <TripDetailCard
                                    title="Trip context"
                                    badges={
                                        <WorkspaceStatusBadge
                                            status={statusLabel(trip.status)}
                                        />
                                    }
                                >
                                    <TripInfoGrid>
                                        <TripInfoItem
                                            label="Bilty"
                                            value={
                                                <Link
                                                    href={urls.lr_show}
                                                    className="text-[#2D8CFF] underline-offset-2 hover:underline"
                                                >
                                                    {trip.lr_number ?? '—'}
                                                </Link>
                                            }
                                        />
                                        <TripInfoItem
                                            label="Customer"
                                            value={trip.customer_name}
                                        />
                                        <TripInfoItem
                                            label="Vehicle"
                                            value={trip.vehicle_registration}
                                        />
                                        <TripInfoItem
                                            label="Driver"
                                            value={trip.driver_name}
                                        />
                                        <TripInfoItem
                                            label="Route"
                                            value={routeLabel || null}
                                        />
                                        <TripInfoItem
                                            label="Freight"
                                            value={
                                                permissions.can_view_freight &&
                                                trip.lr_freight_total
                                                    ? `₹${trip.lr_freight_total}`
                                                    : null
                                            }
                                        />
                                        <TripInfoItem
                                            label="Distance"
                                            value={
                                                trip.trip_distance
                                                    ? `${trip.trip_distance} km`
                                                    : null
                                            }
                                        />
                                        <TripInfoItem
                                            label="Odometer"
                                            value={
                                                trip.starting_odometer ||
                                                trip.ending_odometer
                                                    ? `${trip.starting_odometer ?? '—'} → ${trip.ending_odometer ?? '—'}`
                                                    : null
                                            }
                                        />
                                    </TripInfoGrid>
                                </TripDetailCard>

                                <LrfSection
                                    step={1}
                                    title="Expense history"
                                    description="Recorded trip and diesel expenses for this OwnFleet operation."
                                    tone="receipt"
                                >
                                    <TripExpensesTable
                                        headers={expenseTableHeaders(mutable)}
                                        empty="No expenses recorded yet."
                                    >
                                        {expenses.map((expense) => (
                                            <tr key={expense.id}>
                                                <td>
                                                    {expense.expense_date ??
                                                        '—'}
                                                </td>
                                                <td>
                                                    {expense.category_name ??
                                                        '—'}
                                                    {expense.is_diesel
                                                        ? ' · Diesel'
                                                        : ''}
                                                    {expense.vendor_name
                                                        ? ` · ${expense.vendor_name}`
                                                        : ''}
                                                    {expense.is_credit_payable
                                                        ? expense.settled_at
                                                            ? ' · Credit (Settled)'
                                                            : ' · Credit Payable'
                                                        : ''}
                                                    {expense.payment_mode
                                                        ? ` · ${expense.payment_mode}`
                                                        : ''}
                                                </td>
                                                <td>
                                                    {expense.diesel_odometer ??
                                                        '—'}
                                                </td>
                                                <td>
                                                    {expense.is_diesel &&
                                                    expense.diesel_litres
                                                        ? expense.diesel_litres
                                                        : '—'}
                                                </td>
                                                <td>
                                                    {expense.diesel_rate_per_litre ??
                                                        '—'}
                                                </td>
                                                <td>₹{expense.amount}</td>
                                                <td className="text-muted-foreground">
                                                    {expense.remarks ?? '—'}
                                                </td>
                                                {mutable ? (
                                                    <td className="text-right">
                                                        <Form
                                                            action={urls.remove_expense.replace(
                                                                'EXPENSE_ID',
                                                                expense.id,
                                                            )}
                                                            method="delete"
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    type="submit"
                                                                    variant="destructive"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    Remove
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    </td>
                                                ) : null}
                                            </tr>
                                        ))}
                                    </TripExpensesTable>
                                </LrfSection>

                                {mutable ? (
                                    <LrfSection
                                        step={2}
                                        title="Add expense"
                                        description="Vendor, diesel and other direct trip costs. Diesel amount is calculated from litres × rate."
                                        tone="goods"
                                    >
                                        <Form
                                            action={urls.add_expense}
                                            method="post"
                                            className="grid gap-3 md:grid-cols-2"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <div className="space-y-1.5">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <Label htmlFor="expense_category_id">
                                                                Expense category
                                                            </Label>
                                                            {canQuickCreateCatalogues &&
                                                            urls.expense_categories_store ? (
                                                                <button
                                                                    type="button"
                                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                                    onClick={() =>
                                                                        setExpenseCategoryQuickCreateOpen(
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    + Add
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                        <select
                                                            id="expense_category_id"
                                                            name="expense_category_id"
                                                            required
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            value={
                                                                expenseCategoryId
                                                            }
                                                            onChange={(event) =>
                                                                setExpenseCategoryId(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Select category
                                                            </option>
                                                            {expenseCategories.map(
                                                                (category) => (
                                                                    <option
                                                                        key={
                                                                            category.id
                                                                        }
                                                                        value={
                                                                            category.id
                                                                        }
                                                                    >
                                                                        {
                                                                            category.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="expense_date">
                                                            Expense date
                                                        </Label>
                                                        <Input
                                                            id="expense_date"
                                                            name="expense_date"
                                                            type="date"
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="payment_mode_id">
                                                            Payment mode
                                                        </Label>
                                                        <select
                                                            id="payment_mode_id"
                                                            name="payment_mode_id"
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option value="">
                                                                Optional
                                                            </option>
                                                            {formOptions.payment_modes.map(
                                                                (mode) => (
                                                                    <option
                                                                        key={
                                                                            mode.id
                                                                        }
                                                                        value={
                                                                            mode.id
                                                                        }
                                                                    >
                                                                        {
                                                                            mode.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <Label htmlFor="paid_from_bank_cash_account_id">
                                                                Bank / cash
                                                                account
                                                            </Label>
                                                            {canQuickCreateBankCash ? (
                                                                <button
                                                                    type="button"
                                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                                    onClick={() =>
                                                                        setBankCashQuickCreateOpen(
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    + Add
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                        <select
                                                            id="paid_from_bank_cash_account_id"
                                                            name="paid_from_bank_cash_account_id"
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            value={
                                                                paidFromBankCashAccountId
                                                            }
                                                            onChange={(event) =>
                                                                setPaidFromBankCashAccountId(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        >
                                                            <option value="">
                                                                Optional
                                                            </option>
                                                            {bankCashAccounts.map(
                                                                (account) => (
                                                                    <option
                                                                        key={
                                                                            account.id
                                                                        }
                                                                        value={
                                                                            account.id
                                                                        }
                                                                    >
                                                                        {
                                                                            account.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="vendor_party_id"
                                                            required={
                                                                isCreditPayable
                                                            }
                                                        >
                                                            Vendor / Fuel Pump
                                                        </Label>
                                                        <select
                                                            id="vendor_party_id"
                                                            name="vendor_party_id"
                                                            required={
                                                                isCreditPayable
                                                            }
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            value={
                                                                vendorPartyId
                                                            }
                                                            onChange={(event) =>
                                                                setVendorPartyId(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        >
                                                            <option value="">
                                                                Optional
                                                            </option>
                                                            {(
                                                                formOptions.vendors ??
                                                                []
                                                            ).map((vendor) => (
                                                                <option
                                                                    key={
                                                                        vendor.id
                                                                    }
                                                                    value={
                                                                        vendor.id
                                                                    }
                                                                >
                                                                    {
                                                                        vendor.name
                                                                    }
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>

                                                    <label className="flex items-center gap-2 text-sm md:col-span-2">
                                                        <input
                                                            type="checkbox"
                                                            name="is_credit_payable"
                                                            value="1"
                                                            checked={
                                                                isCreditPayable
                                                            }
                                                            onChange={(event) =>
                                                                setIsCreditPayable(
                                                                    event.target
                                                                        .checked,
                                                                )
                                                            }
                                                        />
                                                        Credit Payable (accrue
                                                        liability to vendor and
                                                        pay later)
                                                    </label>

                                                    <label className="flex items-center gap-2 text-sm md:col-span-2">
                                                        <input
                                                            type="checkbox"
                                                            name="is_diesel"
                                                            value="1"
                                                            checked={isDiesel}
                                                            onChange={(event) =>
                                                                setIsDiesel(
                                                                    event.target
                                                                        .checked,
                                                                )
                                                            }
                                                        />
                                                        Diesel expense (amount =
                                                        litres × rate)
                                                    </label>

                                                    {isDiesel ? (
                                                        <>
                                                            <div className="space-y-1.5">
                                                                <Label htmlFor="diesel_litres">
                                                                    Fuel
                                                                    quantity (L)
                                                                </Label>
                                                                <Input
                                                                    id="diesel_litres"
                                                                    name="diesel_litres"
                                                                    type="number"
                                                                    step="0.001"
                                                                    min="0"
                                                                    required
                                                                />
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                <Label htmlFor="diesel_rate_per_litre">
                                                                    Rate / litre
                                                                </Label>
                                                                <Input
                                                                    id="diesel_rate_per_litre"
                                                                    name="diesel_rate_per_litre"
                                                                    type="number"
                                                                    step="0.0001"
                                                                    min="0"
                                                                    required
                                                                />
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                <Label htmlFor="diesel_odometer">
                                                                    Odometer
                                                                    (KM)
                                                                </Label>
                                                                <Input
                                                                    id="diesel_odometer"
                                                                    name="diesel_odometer"
                                                                    type="number"
                                                                    step="0.1"
                                                                    min="0"
                                                                />
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                <Label htmlFor="planned_diesel_quantity">
                                                                    Expected
                                                                    quantity (L)
                                                                </Label>
                                                                <Input
                                                                    id="planned_diesel_quantity"
                                                                    name="planned_diesel_quantity"
                                                                    type="number"
                                                                    step="0.001"
                                                                    min="0"
                                                                    placeholder="Optional"
                                                                />
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                <Label htmlFor="planned_diesel_rate">
                                                                    Expected
                                                                    rate / litre
                                                                </Label>
                                                                <Input
                                                                    id="planned_diesel_rate"
                                                                    name="planned_diesel_rate"
                                                                    type="number"
                                                                    step="0.0001"
                                                                    min="0"
                                                                    placeholder="Optional"
                                                                />
                                                            </div>
                                                        </>
                                                    ) : (
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="amount">
                                                                Amount
                                                            </Label>
                                                            <Input
                                                                id="amount"
                                                                name="amount"
                                                                type="number"
                                                                step="0.01"
                                                                min="0.01"
                                                                required
                                                            />
                                                        </div>
                                                    )}

                                                    <div className="space-y-1.5 md:col-span-2">
                                                        <Label htmlFor="remarks">
                                                            Remarks
                                                        </Label>
                                                        <Input
                                                            id="remarks"
                                                            name="remarks"
                                                            placeholder="Optional notes"
                                                        />
                                                    </div>

                                                    {errors?.amount ? (
                                                        <p className="text-sm text-destructive md:col-span-2">
                                                            {errors.amount}
                                                        </p>
                                                    ) : null}
                                                    {errors?.diesel_litres ? (
                                                        <p className="text-sm text-destructive md:col-span-2">
                                                            {
                                                                errors.diesel_litres
                                                            }
                                                        </p>
                                                    ) : null}

                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        className="bg-[#2FB36F] text-white hover:bg-[#279960] md:col-span-2"
                                                    >
                                                        Save expense
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </LrfSection>
                                ) : null}

                                {mutable &&
                                permissions.can_view_freight &&
                                urls.driver_advance_store ? (
                                    <LrfSection
                                        step={3}
                                        title="Driver advances & settlement"
                                        description="Issue trip advance, record expense settlement, or capture driver cash recovery."
                                        tone="goods"
                                    >
                                        <Form
                                            action={urls.driver_advance_store}
                                            method="post"
                                            className="grid gap-3 md:grid-cols-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <input
                                                        type="hidden"
                                                        name="trip_id"
                                                        value={trip.id}
                                                    />
                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="adv_driver_party_id"
                                                            required
                                                        >
                                                            Driver
                                                        </Label>
                                                        <select
                                                            id="adv_driver_party_id"
                                                            name="driver_party_id"
                                                            defaultValue={
                                                                trip.driver_party_id ??
                                                                ''
                                                            }
                                                            required
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        >
                                                            <option value="">
                                                                Select driver
                                                            </option>
                                                            {(
                                                                formOptions.drivers ??
                                                                []
                                                            ).map((driver) => (
                                                                <option
                                                                    key={
                                                                        driver.id
                                                                    }
                                                                    value={
                                                                        driver.id
                                                                    }
                                                                >
                                                                    {
                                                                        driver.name
                                                                    }
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="adv_entry_type"
                                                            required
                                                        >
                                                            Entry Type
                                                        </Label>
                                                        <select
                                                            id="adv_entry_type"
                                                            name="entry_type"
                                                            required
                                                            defaultValue="advance"
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        >
                                                            <option value="advance">
                                                                Advance Paid
                                                            </option>
                                                            <option value="settlement">
                                                                Expense
                                                                Settlement
                                                            </option>
                                                            <option value="recovery">
                                                                Cash Recovery
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="adv_amount"
                                                            required
                                                        >
                                                            Amount
                                                        </Label>
                                                        <Input
                                                            id="adv_amount"
                                                            name="amount"
                                                            type="number"
                                                            step="0.01"
                                                            min="0.01"
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="adv_bank_cash_account_id">
                                                            Bank / Cash Account
                                                        </Label>
                                                        <select
                                                            id="adv_bank_cash_account_id"
                                                            name="bank_cash_account_id"
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option value="">
                                                                Adjustment /
                                                                None
                                                            </option>
                                                            {bankCashAccounts.map(
                                                                (account) => (
                                                                    <option
                                                                        key={
                                                                            account.id
                                                                        }
                                                                        value={
                                                                            account.id
                                                                        }
                                                                    >
                                                                        {
                                                                            account.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5 md:col-span-2">
                                                        <Label htmlFor="adv_remark">
                                                            Remark
                                                        </Label>
                                                        <Input
                                                            id="adv_remark"
                                                            name="remark"
                                                            placeholder="Optional advance/settlement note"
                                                        />
                                                    </div>
                                                    <div className="md:col-span-3">
                                                        <Button
                                                            type="submit"
                                                            variant="secondary"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Record Driver Entry
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </Form>
                                        {driverAdvances &&
                                        driverAdvances.entries.length > 0 ? (
                                            <ul className="mt-3 divide-y rounded-md border text-xs">
                                                {driverAdvances.entries.map(
                                                    (entry) => (
                                                        <li
                                                            key={entry.id}
                                                            className="flex items-center justify-between px-3 py-2"
                                                        >
                                                            <span>
                                                                {
                                                                    entry.entry_date
                                                                }{' '}
                                                                ·{' '}
                                                                {entry.driver_name ??
                                                                    'Driver'}{' '}
                                                                (
                                                                {
                                                                    entry.entry_type
                                                                }
                                                                )
                                                            </span>
                                                            <span className="font-medium">
                                                                ₹{entry.amount}
                                                            </span>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ) : null}
                                    </LrfSection>
                                ) : null}

                                {mutable &&
                                permissions.can_view_freight &&
                                urls.vendor_payment_store &&
                                vendorPayables &&
                                vendorPayables.unpaid_expenses.length > 0 ? (
                                    <LrfSection
                                        step={4}
                                        title="Credit vendor payables"
                                        description="Settle unpaid fuel pump or vendor credit expenses."
                                        tone="optional"
                                    >
                                        <Form
                                            action={urls.vendor_payment_store}
                                            method="post"
                                            className="grid gap-3 md:grid-cols-3"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="vp_vendor_party_id"
                                                            required
                                                        >
                                                            Vendor
                                                        </Label>
                                                        <select
                                                            id="vp_vendor_party_id"
                                                            name="vendor_party_id"
                                                            required
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        >
                                                            <option value="">
                                                                Select vendor
                                                            </option>
                                                            {vendorPayables.vendor_balances
                                                                .filter(
                                                                    (v) =>
                                                                        Number(
                                                                            v.balance_due ??
                                                                                0,
                                                                        ) > 0,
                                                                )
                                                                .map((v) => (
                                                                    <option
                                                                        key={
                                                                            v.vendor_party_id
                                                                        }
                                                                        value={
                                                                            v.vendor_party_id
                                                                        }
                                                                    >
                                                                        {
                                                                            v.vendor_name
                                                                        }{' '}
                                                                        (Due ₹
                                                                        {
                                                                            v.balance_due
                                                                        }
                                                                        )
                                                                    </option>
                                                                ))}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="vp_amount"
                                                            required
                                                        >
                                                            Payment Amount
                                                        </Label>
                                                        <Input
                                                            id="vp_amount"
                                                            name="amount"
                                                            type="number"
                                                            step="0.01"
                                                            min="0.01"
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label
                                                            htmlFor="vp_bank_cash_account_id"
                                                            required
                                                        >
                                                            Bank / Cash Account
                                                        </Label>
                                                        <select
                                                            id="vp_bank_cash_account_id"
                                                            name="bank_cash_account_id"
                                                            required
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue={
                                                                bankCashAccounts[0]
                                                                    ?.id ?? ''
                                                            }
                                                        >
                                                            {bankCashAccounts.map(
                                                                (account) => (
                                                                    <option
                                                                        key={
                                                                            account.id
                                                                        }
                                                                        value={
                                                                            account.id
                                                                        }
                                                                    >
                                                                        {
                                                                            account.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5 md:col-span-3">
                                                        <Button
                                                            type="submit"
                                                            variant="secondary"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Pay Vendor Balance
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </Form>
                                    </LrfSection>
                                ) : null}
                            </>
                        }
                        side={
                            <>
                                {permissions.can_view_freight ? (
                                    <TripFinancialSummary
                                        profit={profit}
                                        distanceKm={
                                            profit.trip_distance ??
                                            trip.trip_distance
                                        }
                                    />
                                ) : null}
                                <TripDieselCard
                                    profit={profit}
                                    dieselLitres={
                                        profit.diesel_litres_total ??
                                        dieselLitres
                                    }
                                    distanceKm={
                                        profit.trip_distance ??
                                        trip.trip_distance
                                    }
                                />
                                <TripDetailCard
                                    title="Diesel guidance"
                                    className="border-dashed"
                                >
                                    <div className="space-y-2 p-4 text-sm text-slate-600">
                                        <p className="flex items-start gap-2">
                                            <Fuel
                                                className="mt-0.5 size-4 shrink-0"
                                                aria-hidden
                                            />
                                            Record pump fills as diesel expenses
                                            with quantity and rate. Expected
                                            quantity/rate enable variance when
                                            provided.
                                        </p>
                                        {!profit.diesel_expected_vs_actual_available ? (
                                            <p className="text-xs text-slate-500">
                                                Expected-vs-actual diesel is
                                                unavailable until planned
                                                quantity or amount is recorded
                                                on a diesel expense.
                                            </p>
                                        ) : null}
                                    </div>
                                </TripDetailCard>
                            </>
                        }
                    />
                </TripDetailPage>
            </LrfPage>

            {documentDrawer ? (
                <DocumentDrawer
                    {...documentDrawer}
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                />
            ) : null}

            {expenseCategoryQuickCreateOpen && urls.expense_categories_store ? (
                <QuickCreateCatalogueSheet
                    open={expenseCategoryQuickCreateOpen}
                    onOpenChange={setExpenseCategoryQuickCreateOpen}
                    title="Expense Category"
                    storeUrl={urls.expense_categories_store}
                    onCreated={(item: QuickCreatedCatalogueItem) => {
                        setExpenseCategories((current) => {
                            if (current.some((entry) => entry.id === item.id)) {
                                return current;
                            }
                            return [
                                ...current,
                                { id: item.id, name: item.name },
                            ];
                        });
                        setExpenseCategoryId(item.id);
                        setExpenseCategoryQuickCreateOpen(false);
                    }}
                />
            ) : null}

            {bankCashQuickCreateOpen && urls.bank_cash_store ? (
                <QuickCreateBankCashSheet
                    open={bankCashQuickCreateOpen}
                    onOpenChange={setBankCashQuickCreateOpen}
                    storeUrl={urls.bank_cash_store}
                    onCreated={(account: QuickCreatedBankCashAccount) => {
                        setBankCashAccounts((current) => {
                            if (
                                current.some((entry) => entry.id === account.id)
                            ) {
                                return current;
                            }
                            return [
                                ...current,
                                { id: account.id, name: account.name },
                            ];
                        });
                        setPaidFromBankCashAccountId(account.id);
                        setBankCashQuickCreateOpen(false);
                    }}
                />
            ) : null}
        </>
    );
}
