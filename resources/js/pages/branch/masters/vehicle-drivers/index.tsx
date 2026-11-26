import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    History,
    Plus,
    RefreshCw,
    UserMinus,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import InputError from '@/components/input-error';
import {
    VehicleDriverAssignmentModal,
    type DriverAssignmentVehicleOption,
} from '@/components/masters/vehicle-driver-assignment-modal';
import {
    SearchablePartySelect,
    type PartyOption,
} from '@/components/searchable-party-select';
import { Badge } from '@/components/ui/badge';
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
import { formatDisplayDate } from '@/lib/dates';

type RegisterRow = {
    vehicle_id: string;
    registration_number: string;
    vehicle_is_active: boolean;
    truck_type_name: string | null;
    assignment_id: string | null;
    driver_party_id: string | null;
    driver_name: string | null;
    driver_contact_number: string | null;
    driver_license_number: string | null;
    driver_license_expiry: string | null;
    license_expired: boolean;
    assignment_date: string | null;
    assigned_to: string | null;
    scheduled_driver_name: string | null;
    scheduled_from: string | null;
    assignment_status: 'assigned' | 'scheduled' | 'unassigned';
    history_count: number;
    assign_url: string;
    remove_url: string;
    history_url: string;
    vehicle_url: string;
};

type AttendanceRow = {
    vehicle_id: string;
    registration_number: string;
    primary_driver_party_id: string | null;
    primary_driver_name: string | null;
    primary_driver_contact: string | null;
    status: 'present' | 'absent' | 'substitute' | 'no_work_day' | 'unassigned';
    status_label: string;
    attendance_id: string | null;
    temporary_driver_party_id: string | null;
    temporary_driver_name: string | null;
    remarks: string | null;
    active_trip_lr: string | null;
    can_mark: boolean;
    clear_url: string;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    filters: { q: string; status: string; date: string };
    permissions: {
        can_assign: boolean;
        can_remove: boolean;
        can_mark_attendance: boolean;
        can_record_advance?: boolean;
        can_view_freight?: boolean;
        can_create_party: boolean;
        can_create_vehicle: boolean;
    };
    rows: RegisterRow[];
    attendance: {
        date: string;
        summary: {
            total: number;
            present: number;
            absent: number;
            substitute: number;
            no_work_day: number;
            unassigned: number;
        };
        busy_driver_ids: Record<string, string>;
        rows: AttendanceRow[];
    };
    driverAdvanceSummary?: {
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
    bankCashAccounts?: Array<{ id: string; name: string }>;
    driverOptions: PartyOption[];
    ownVehicleOptions: DriverAssignmentVehicleOption[];
    urls: {
        index: string;
        assign_base: string;
        attendance_mark: string;
        driver_advance_store?: string;
        parties_options: string;
        parties_store: string;
        vehicles_index: string;
        dashboard: string;
    };
};

function assignmentBadge(status: RegisterRow['assignment_status']) {
    if (status === 'assigned') {
        return <Badge variant="secondary">Assigned</Badge>;
    }
    if (status === 'scheduled') {
        return <Badge variant="outline">Scheduled</Badge>;
    }
    return <Badge variant="destructive">Unassigned</Badge>;
}

function attendanceBadge(status: AttendanceRow['status']) {
    switch (status) {
        case 'present':
            return <Badge variant="secondary">Present (Assigned)</Badge>;
        case 'substitute':
            return <Badge variant="default">Absent · Substitute</Badge>;
        case 'absent':
            return <Badge variant="destructive">Absent</Badge>;
        case 'no_work_day':
            return <Badge variant="outline">No Work Day</Badge>;
        default:
            return <Badge variant="outline">Unassigned</Badge>;
    }
}

export default function VehicleDriversIndex({
    branch,
    filters,
    permissions,
    rows,
    attendance,
    driverAdvanceSummary,
    bankCashAccounts = [],
    driverOptions,
    ownVehicleOptions,
    urls,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null; error?: string | null };
    }>().props;

    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [attendanceDate, setAttendanceDate] = useState(attendance.date);

    const [assignModalOpen, setAssignModalOpen] = useState(false);
    const [assignTarget, setAssignTarget] = useState<{
        vehicle: {
            id: string;
            registration_number: string;
            current_driver_name?: string | null;
        } | null;
        mode: 'assign' | 'change';
    }>({ vehicle: null, mode: 'assign' });

    const [removeTarget, setRemoveTarget] = useState<RegisterRow | null>(null);
    const [removeEffectiveDate, setRemoveEffectiveDate] = useState(
        attendance.date,
    );
    const [removeRemarks, setRemoveRemarks] = useState('');
    const [removeProcessing, setRemoveProcessing] = useState(false);

    const [attendanceTarget, setAttendanceTarget] =
        useState<AttendanceRow | null>(null);
    const [attStatus, setAttStatus] = useState<'ABSENT' | 'NO_WORK_DAY'>(
        'ABSENT',
    );
    const [substituteDriverId, setSubstituteDriverId] = useState('');
    const [attRemarks, setAttRemarks] = useState('');
    const [attProcessing, setAttProcessing] = useState(false);
    const [attErrors, setAttErrors] = useState<Record<string, string>>({});

    const applyFilters = (nextDate?: string) => {
        router.get(
            urls.index,
            {
                q: q.trim() || undefined,
                status: status || undefined,
                date: (nextDate ?? attendanceDate) || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitRemove = (event: React.FormEvent) => {
        event.preventDefault();
        if (!removeTarget) {
            return;
        }
        setRemoveProcessing(true);
        router.post(
            removeTarget.remove_url,
            {
                effective_date: removeEffectiveDate || null,
                remarks: removeRemarks || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRemoveTarget(null);
                    setRemoveRemarks('');
                },
                onFinish: () => setRemoveProcessing(false),
            },
        );
    };

    const openAttendanceModal = (row: AttendanceRow) => {
        setAttendanceTarget(row);
        setAttStatus(row.status === 'no_work_day' ? 'NO_WORK_DAY' : 'ABSENT');
        setSubstituteDriverId(row.temporary_driver_party_id ?? '');
        setAttRemarks(row.remarks ?? '');
        setAttErrors({});
    };

    const submitAttendance = (event: React.FormEvent) => {
        event.preventDefault();
        if (!attendanceTarget) {
            return;
        }
        setAttProcessing(true);
        setAttErrors({});
        router.post(
            urls.attendance_mark,
            {
                vehicle_id: attendanceTarget.vehicle_id,
                attendance_date: attendance.date,
                status: attStatus,
                temporary_driver_party_id:
                    attStatus === 'ABSENT' && substituteDriverId
                        ? substituteDriverId
                        : null,
                remarks: attRemarks || null,
            },
            {
                preserveScroll: true,
                onError: (serverErrors) => {
                    setAttErrors(serverErrors as Record<string, string>);
                },
                onSuccess: () => {
                    setAttendanceTarget(null);
                },
                onFinish: () => setAttProcessing(false),
            },
        );
    };

    const busyHint =
        substituteDriverId && attendance.busy_driver_ids[substituteDriverId]
            ? attendance.busy_driver_ids[substituteDriverId]
            : null;

    return (
        <>
            <Head title={`Vehicle Drivers · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title="Vehicle Drivers"
                    toolbar={
                        permissions.can_assign &&
                        ownVehicleOptions.length > 0 ? (
                            <Button
                                type="button"
                                variant="primary"
                                size="sm"
                                className="gap-1.5"
                                onClick={() => {
                                    setAssignTarget({
                                        vehicle: null,
                                        mode: 'assign',
                                    });
                                    setAssignModalOpen(true);
                                }}
                            >
                                <Plus className="size-3.5" />
                                Assign Driver
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
                            applyFilters();
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="vd-q">Search</Label>
                            <Input
                                id="vd-q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Vehicle, driver, phone, licence…"
                                className="min-w-[14rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="vd-status">Status</Label>
                            <select
                                id="vd-status"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">All vehicles</option>
                                <option value="assigned">Assigned</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="unassigned">Unassigned</option>
                            </select>
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Vehicle Registration</th>
                                    <th>Primary Driver</th>
                                    <th>Contact Number</th>
                                    <th>Assignment Date</th>
                                    <th>Driving Licence & Expiry</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <p className="app-index-empty">
                                                No Own Vehicles found in this
                                                branch. Add an Own Vehicle in{' '}
                                                <Link
                                                    href={urls.vehicles_index}
                                                    className="font-medium text-primary underline"
                                                >
                                                    Vehicle Master
                                                </Link>{' '}
                                                to assign a Primary Driver.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row) => (
                                        <tr key={row.vehicle_id}>
                                            <td className="font-medium">
                                                <Link
                                                    href={row.vehicle_url}
                                                    className="text-primary hover:underline"
                                                >
                                                    {row.registration_number}
                                                </Link>
                                                {row.truck_type_name ? (
                                                    <div className="text-xs font-normal text-muted-foreground">
                                                        {row.truck_type_name}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td>
                                                {row.driver_name ?? '—'}
                                                {row.scheduled_driver_name &&
                                                row.assignment_status ===
                                                    'scheduled' ? (
                                                    <div className="text-xs text-muted-foreground">
                                                        Scheduled:{' '}
                                                        {
                                                            row.scheduled_driver_name
                                                        }
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td>
                                                {row.driver_contact_number ??
                                                    '—'}
                                            </td>
                                            <td>
                                                {row.assignment_date
                                                    ? formatDisplayDate(
                                                          row.assignment_date,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td>
                                                {row.driver_license_number ||
                                                row.driver_license_expiry ? (
                                                    <div className="space-y-0.5 text-xs">
                                                        <div>
                                                            {row.driver_license_number ??
                                                                'No number'}
                                                        </div>
                                                        <div
                                                            className={
                                                                row.license_expired
                                                                    ? 'font-medium text-destructive'
                                                                    : 'text-muted-foreground'
                                                            }
                                                        >
                                                            {row.driver_license_expiry
                                                                ? `Exp: ${formatDisplayDate(row.driver_license_expiry)}`
                                                                : 'No expiry'}
                                                            {row.license_expired
                                                                ? ' (Expired)'
                                                                : ''}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td>
                                                {assignmentBadge(
                                                    row.assignment_status,
                                                )}
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap items-center justify-center gap-1.5">
                                                    {permissions.can_assign ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-7 gap-1 px-2 text-xs"
                                                            onClick={() => {
                                                                setAssignTarget(
                                                                    {
                                                                        vehicle:
                                                                            {
                                                                                id: row.vehicle_id,
                                                                                registration_number:
                                                                                    row.registration_number,
                                                                                current_driver_name:
                                                                                    row.driver_name,
                                                                            },
                                                                        mode:
                                                                            row.driver_party_id !==
                                                                            null
                                                                                ? 'change'
                                                                                : 'assign',
                                                                    },
                                                                );
                                                                setAssignModalOpen(
                                                                    true,
                                                                );
                                                            }}
                                                        >
                                                            {row.driver_party_id !==
                                                            null ? (
                                                                <>
                                                                    <RefreshCw className="size-3" />
                                                                    Change
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <UserPlus className="size-3" />
                                                                    Assign
                                                                </>
                                                            )}
                                                        </Button>
                                                    ) : null}
                                                    {permissions.can_remove &&
                                                    row.driver_party_id !==
                                                        null ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-7 gap-1 px-2 text-xs text-destructive hover:text-destructive"
                                                            onClick={() => {
                                                                setRemoveTarget(
                                                                    row,
                                                                );
                                                                setRemoveEffectiveDate(
                                                                    attendance.date,
                                                                );
                                                            }}
                                                        >
                                                            <UserMinus className="size-3" />
                                                            Remove
                                                        </Button>
                                                    ) : null}
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 gap-1 px-2 text-xs"
                                                    >
                                                        <Link
                                                            href={
                                                                row.history_url
                                                            }
                                                        >
                                                            <History className="size-3" />
                                                            History (
                                                            {row.history_count})
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>

                <div className="mt-6">
                    <AppIndexListCard
                        title={`Daily Driver Attendance (${formatDisplayDate(attendance.date)})`}
                        toolbar={
                            <div className="flex flex-wrap items-center gap-2">
                                <CalendarDays className="size-4 text-muted-foreground" />
                                <Input
                                    type="date"
                                    aria-label="Attendance date"
                                    value={attendanceDate}
                                    onChange={(e) => {
                                        setAttendanceDate(e.target.value);
                                        applyFilters(e.target.value);
                                    }}
                                    className="h-8 w-auto text-xs"
                                />
                            </div>
                        }
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/20 px-4 py-3 text-xs">
                            <p className="text-muted-foreground">
                                Assigned primary drivers are automatically
                                counted as <strong>Present</strong> unless an
                                exception (Absent, Substitute, or No Work Day)
                                is marked.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Badge variant="secondary">
                                    Present: {attendance.summary.present}
                                </Badge>
                                <Badge variant="destructive">
                                    Absent: {attendance.summary.absent}
                                </Badge>
                                <Badge variant="default">
                                    Substitutes: {attendance.summary.substitute}
                                </Badge>
                                <Badge variant="outline">
                                    No Work: {attendance.summary.no_work_day}
                                </Badge>
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="app-index-table min-w-full">
                                <thead>
                                    <tr>
                                        <th>Vehicle</th>
                                        <th>Primary Driver</th>
                                        <th>Active Trip</th>
                                        <th>Attendance Status</th>
                                        <th>Substitute Driver</th>
                                        <th>Remarks</th>
                                        <th className="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {attendance.rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={7}>
                                                <p className="app-index-empty">
                                                    No Own Vehicles available
                                                    for attendance on this date.
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        attendance.rows.map((row) => (
                                            <tr key={row.vehicle_id}>
                                                <td className="font-medium">
                                                    {row.registration_number}
                                                </td>
                                                <td>
                                                    {row.primary_driver_name ??
                                                        '—'}
                                                </td>
                                                <td>
                                                    {row.active_trip_lr ?? '—'}
                                                </td>
                                                <td>
                                                    {attendanceBadge(
                                                        row.status,
                                                    )}
                                                </td>
                                                <td>
                                                    {row.temporary_driver_name ??
                                                        '—'}
                                                </td>
                                                <td className="max-w-[14rem] truncate">
                                                    {row.remarks ?? '—'}
                                                </td>
                                                <td>
                                                    <div className="flex flex-wrap items-center justify-center gap-1.5">
                                                        {permissions.can_mark_attendance &&
                                                        row.can_mark ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-7 px-2 text-xs"
                                                                onClick={() =>
                                                                    openAttendanceModal(
                                                                        row,
                                                                    )
                                                                }
                                                            >
                                                                {row.attendance_id
                                                                    ? 'Edit Exception'
                                                                    : 'Mark Exception'}
                                                            </Button>
                                                        ) : null}
                                                        {permissions.can_mark_attendance &&
                                                        row.attendance_id ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-7 px-2 text-xs"
                                                                onClick={() => {
                                                                    router.delete(
                                                                        row.clear_url,
                                                                        {
                                                                            data: {
                                                                                attendance_date:
                                                                                    attendance.date,
                                                                            },
                                                                            preserveScroll: true,
                                                                        },
                                                                    );
                                                                }}
                                                            >
                                                                Reset to Present
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </AppIndexListCard>
                </div>

                {permissions.can_view_freight && driverAdvanceSummary ? (
                    <div className="mt-6">
                        <AppIndexListCard
                            title={`Driver Advance Balances & Settlement${driverAdvanceSummary.total_outstanding ? ` · Total ₹${driverAdvanceSummary.total_outstanding}` : ''}`}
                        >
                            {permissions.can_record_advance &&
                            urls.driver_advance_store ? (
                                <Form
                                    action={urls.driver_advance_store}
                                    method="post"
                                    className="grid gap-3 border-b p-4 md:grid-cols-5"
                                >
                                    {({ processing }) => (
                                        <>
                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor="vd_driver_party_id"
                                                    required
                                                >
                                                    Driver
                                                </Label>
                                                <select
                                                    id="vd_driver_party_id"
                                                    name="driver_party_id"
                                                    required
                                                    className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                >
                                                    <option value="">
                                                        Select driver
                                                    </option>
                                                    {driverOptions.map((d) => (
                                                        <option
                                                            key={d.id}
                                                            value={d.id}
                                                        >
                                                            {d.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor="vd_entry_type"
                                                    required
                                                >
                                                    Type
                                                </Label>
                                                <select
                                                    id="vd_entry_type"
                                                    name="entry_type"
                                                    defaultValue="advance"
                                                    className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                >
                                                    <option value="advance">
                                                        Advance
                                                    </option>
                                                    <option value="settlement">
                                                        Settlement
                                                    </option>
                                                    <option value="recovery">
                                                        Recovery
                                                    </option>
                                                </select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label
                                                    htmlFor="vd_amount"
                                                    required
                                                >
                                                    Amount
                                                </Label>
                                                <Input
                                                    id="vd_amount"
                                                    name="amount"
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label htmlFor="vd_bank_cash_account_id">
                                                    Bank / Cash
                                                </Label>
                                                <select
                                                    id="vd_bank_cash_account_id"
                                                    name="bank_cash_account_id"
                                                    className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    defaultValue=""
                                                >
                                                    <option value="">
                                                        Adjustment / None
                                                    </option>
                                                    {bankCashAccounts.map(
                                                        (acc) => (
                                                            <option
                                                                key={acc.id}
                                                                value={acc.id}
                                                            >
                                                                {acc.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="flex items-end">
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="w-full bg-[#2FB36F] text-white hover:bg-[#279960]"
                                                >
                                                    Save Entry
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            ) : null}
                            <div className="overflow-x-auto">
                                <table className="app-index-table min-w-full">
                                    <thead>
                                        <tr>
                                            <th>Driver</th>
                                            <th>Advances</th>
                                            <th>Settlements</th>
                                            <th>Recoveries</th>
                                            <th>Net Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {driverAdvanceSummary.driver_balances
                                            .length === 0 ? (
                                            <tr>
                                                <td colSpan={5}>
                                                    <p className="app-index-empty">
                                                        No driver advance
                                                        balances recorded yet.
                                                    </p>
                                                </td>
                                            </tr>
                                        ) : (
                                            driverAdvanceSummary.driver_balances.map(
                                                (b) => (
                                                    <tr key={b.driver_party_id}>
                                                        <td className="font-medium">
                                                            {b.driver_name}
                                                        </td>
                                                        <td>₹{b.advances}</td>
                                                        <td>
                                                            ₹{b.settlements}
                                                        </td>
                                                        <td>₹{b.recoveries}</td>
                                                        <td className="font-semibold">
                                                            ₹{b.net_balance}
                                                        </td>
                                                    </tr>
                                                ),
                                            )
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </AppIndexListCard>
                    </div>
                ) : null}
            </AppIndexPage>

            <VehicleDriverAssignmentModal
                open={assignModalOpen}
                onOpenChange={setAssignModalOpen}
                assignBaseUrl={urls.assign_base}
                fixedVehicle={assignTarget.vehicle}
                vehicleOptions={ownVehicleOptions}
                driverOptions={driverOptions}
                partiesOptionsUrl={urls.parties_options}
                partiesStoreUrl={urls.parties_store}
                canCreateParty={permissions.can_create_party}
                defaultDate={attendance.date}
                mode={assignTarget.mode}
            />

            <Dialog
                open={removeTarget !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRemoveTarget(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Remove Primary Driver</DialogTitle>
                        <DialogDescription>
                            End the active assignment for{' '}
                            <strong>{removeTarget?.driver_name}</strong> on{' '}
                            <strong>{removeTarget?.registration_number}</strong>
                            . Assignment history and historical Trips remain
                            intact.
                        </DialogDescription>
                    </DialogHeader>
                    <form className="space-y-4" onSubmit={submitRemove}>
                        <div className="space-y-1.5">
                            <Label htmlFor="remove_effective_date" required>
                                Effective End Date
                            </Label>
                            <Input
                                id="remove_effective_date"
                                type="date"
                                value={removeEffectiveDate}
                                onChange={(e) =>
                                    setRemoveEffectiveDate(e.target.value)
                                }
                                required
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="remove_remarks">Remarks</Label>
                            <Input
                                id="remove_remarks"
                                value={removeRemarks}
                                onChange={(e) =>
                                    setRemoveRemarks(e.target.value)
                                }
                                placeholder="Reason for driver removal"
                            />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRemoveTarget(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={removeProcessing}
                            >
                                {removeProcessing
                                    ? 'Removing…'
                                    : 'Confirm Remove'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={attendanceTarget !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setAttendanceTarget(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            Mark Attendance Exception —{' '}
                            {attendanceTarget?.registration_number}
                        </DialogTitle>
                        <DialogDescription>
                            Date: {formatDisplayDate(attendance.date)} · Primary
                            Driver:{' '}
                            {attendanceTarget?.primary_driver_name ?? '—'}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={submitAttendance}
                        noValidate
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="att_status" required>
                                Attendance Exception
                            </Label>
                            <select
                                id="att_status"
                                value={attStatus}
                                onChange={(e) => {
                                    const next = e.target.value as
                                        | 'ABSENT'
                                        | 'NO_WORK_DAY';
                                    setAttStatus(next);
                                    if (next === 'NO_WORK_DAY') {
                                        setSubstituteDriverId('');
                                    }
                                }}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                required
                            >
                                <option value="ABSENT">Absent</option>
                                <option value="NO_WORK_DAY">No Work Day</option>
                            </select>
                            <InputError message={attErrors.status} />
                        </div>

                        {attStatus === 'ABSENT' ? (
                            <div className="space-y-1.5">
                                <SearchablePartySelect
                                    id="att_substitute_driver"
                                    name="temporary_driver_party_id"
                                    label="Substitute Driver (Optional)"
                                    options={driverOptions.filter(
                                        (d) =>
                                            d.id !==
                                            attendanceTarget?.primary_driver_party_id,
                                    )}
                                    searchUrl={urls.parties_options}
                                    role="driver"
                                    createStoreUrl={
                                        permissions.can_create_party
                                            ? urls.parties_store
                                            : undefined
                                    }
                                    createLabel="Add"
                                    createRole="driver"
                                    value={substituteDriverId}
                                    onValueChange={setSubstituteDriverId}
                                    emptyMessage="No available substitute drivers."
                                    error={attErrors.temporary_driver_party_id}
                                />
                                {busyHint ? (
                                    <p className="text-xs font-medium text-amber-600">
                                        Warning: {busyHint}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        <div className="space-y-1.5">
                            <Label htmlFor="att_remarks">Remarks</Label>
                            <Input
                                id="att_remarks"
                                value={attRemarks}
                                onChange={(e) => setAttRemarks(e.target.value)}
                                placeholder="Optional attendance remark"
                            />
                            <InputError message={attErrors.remarks} />
                        </div>

                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAttendanceTarget(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={attProcessing}
                            >
                                {attProcessing ? 'Saving…' : 'Save Attendance'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
