import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FileText, Fuel, Route, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
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
    driver_name: string | null;
    starting_odometer: string | null;
    ending_odometer: string | null;
    trip_distance: string | null;
    lr_freight_total: string | null;
};

type FormOptions = {
    expense_categories: Array<{ id: string; name: string }>;
    payment_modes: Array<{ id: string; name: string }>;
    bank_cash_accounts: Array<{ id: string; name: string }>;
};

type Props = {
    trip: TripSummary;
    expenses: ExpenseRow[];
    profit: TripProfitProps;
    formOptions: FormOptions;
    permissions: {
        can_edit: boolean;
        can_view_freight: boolean;
    };
    urls: {
        show: string;
        multi_point?: string;
        lr_show: string;
        index: string;
        add_expense: string;
        remove_expense: string;
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
                                                        <Label htmlFor="expense_category_id">
                                                            Expense category
                                                        </Label>
                                                        <select
                                                            id="expense_category_id"
                                                            name="expense_category_id"
                                                            required
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Select category
                                                            </option>
                                                            {formOptions.expense_categories.map(
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
                                                        <Label htmlFor="paid_from_bank_cash_account_id">
                                                            Bank / cash account
                                                        </Label>
                                                        <select
                                                            id="paid_from_bank_cash_account_id"
                                                            name="paid_from_bank_cash_account_id"
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            defaultValue=""
                                                        >
                                                            <option value="">
                                                                Optional
                                                            </option>
                                                            {formOptions.bank_cash_accounts.map(
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
        </>
    );
}
