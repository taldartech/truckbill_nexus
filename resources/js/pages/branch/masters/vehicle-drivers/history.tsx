import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, RefreshCw, Truck, UserMinus, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import {
    VehicleDriverAssignmentModal,
    VehicleDriverRemoveModal,
} from '@/components/masters/vehicle-driver-assignment-modal';
import type { PartyOption } from '@/components/searchable-party-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDisplayDate } from '@/lib/dates';

type AssignmentRow = {
    id: string;
    driver_party_id: string;
    driver_name: string;
    driver_contact_number: string | null;
    driver_license_number: string | null;
    driver_license_expiry: string | null;
    assigned_from: string;
    assigned_to: string | null;
    status: 'active' | 'scheduled' | 'ended';
    remarks: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    vehicle: {
        id: string;
        registration_number: string;
        ownership: string | null;
        is_active: boolean;
        primary_driver_party_id: string | null;
        primary_driver_name: string | null;
    };
    permissions: {
        can_assign: boolean;
        can_remove: boolean;
        can_create_party: boolean;
    };
    assignments: AssignmentRow[];
    driverOptions: PartyOption[];
    urls: {
        index: string;
        vehicle: string;
        assign: string;
        remove: string;
        parties_options: string;
        parties_store: string;
    };
};

export default function VehicleDriverHistory({
    vehicle,
    permissions,
    assignments,
    driverOptions,
    urls,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [assignOpen, setAssignOpen] = useState(false);
    const [removeOpen, setRemoveOpen] = useState(false);

    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title={`Driver History · ${vehicle.registration_number}`} />
            <AppIndexPage>
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <Button asChild variant="outline" size="sm">
                        <Link href={urls.index}>
                            <ArrowLeft className="size-4" />
                            Vehicle Drivers
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href={urls.vehicle}>
                            <Truck className="size-4" />
                            Vehicle Detail
                        </Link>
                    </Button>
                </div>

                <AppIndexListCard
                    title={`Driver Assignment History · ${vehicle.registration_number}`}
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            {permissions.can_assign ? (
                                <Button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() => setAssignOpen(true)}
                                >
                                    {vehicle.primary_driver_party_id ? (
                                        <>
                                            <RefreshCw className="size-3.5" />
                                            Change Driver
                                        </>
                                    ) : (
                                        <>
                                            <UserPlus className="size-3.5" />
                                            Assign Driver
                                        </>
                                    )}
                                </Button>
                            ) : null}
                            {permissions.can_remove &&
                            vehicle.primary_driver_party_id ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5 text-destructive hover:text-destructive"
                                    onClick={() => setRemoveOpen(true)}
                                >
                                    <UserMinus className="size-3.5" />
                                    Remove Current Driver
                                </Button>
                            ) : null}
                        </div>
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

                    <div className="border-b border-border bg-muted/20 px-4 py-3 text-sm">
                        <span className="text-muted-foreground">
                            Current Primary Driver:{' '}
                        </span>
                        <span className="font-medium text-foreground">
                            {vehicle.primary_driver_name ?? 'Unassigned'}
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Driver</th>
                                    <th>Contact</th>
                                    <th>Licence</th>
                                    <th>Effective From</th>
                                    <th>Effective To</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <p className="app-index-empty">
                                                No driver assignment history
                                                recorded for this vehicle yet.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    assignments.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.driver_name}
                                            </td>
                                            <td>
                                                {row.driver_contact_number ??
                                                    '—'}
                                            </td>
                                            <td>
                                                {row.driver_license_number ??
                                                    '—'}
                                                {row.driver_license_expiry ? (
                                                    <div className="text-xs text-muted-foreground">
                                                        Exp:{' '}
                                                        {formatDisplayDate(
                                                            row.driver_license_expiry,
                                                        )}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td>
                                                {formatDisplayDate(
                                                    row.assigned_from,
                                                )}
                                            </td>
                                            <td>
                                                {row.assigned_to
                                                    ? formatDisplayDate(
                                                          row.assigned_to,
                                                      )
                                                    : 'Ongoing'}
                                            </td>
                                            <td>
                                                <Badge
                                                    variant={
                                                        row.status === 'active'
                                                            ? 'secondary'
                                                            : row.status ===
                                                                'scheduled'
                                                              ? 'default'
                                                              : 'outline'
                                                    }
                                                >
                                                    {row.status === 'active'
                                                        ? 'Active'
                                                        : row.status ===
                                                            'scheduled'
                                                          ? 'Scheduled'
                                                          : 'Ended'}
                                                </Badge>
                                            </td>
                                            <td className="max-w-[16rem] truncate">
                                                {row.remarks ?? '—'}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            <VehicleDriverAssignmentModal
                open={assignOpen}
                onOpenChange={setAssignOpen}
                fixedVehicle={{
                    id: vehicle.id,
                    registration_number: vehicle.registration_number,
                    current_driver_name: vehicle.primary_driver_name,
                }}
                driverOptions={driverOptions}
                partiesOptionsUrl={urls.parties_options}
                partiesStoreUrl={urls.parties_store}
                canCreateParty={permissions.can_create_party}
                defaultDate={today}
                mode={vehicle.primary_driver_party_id ? 'change' : 'assign'}
            />
            {permissions.can_remove && vehicle.primary_driver_party_id ? (
                <VehicleDriverRemoveModal
                    open={removeOpen}
                    onOpenChange={setRemoveOpen}
                    removeUrl={urls.remove}
                    registrationNumber={vehicle.registration_number}
                    driverName={vehicle.primary_driver_name ?? 'Primary Driver'}
                    defaultDate={today}
                />
            ) : null}
        </>
    );
}
