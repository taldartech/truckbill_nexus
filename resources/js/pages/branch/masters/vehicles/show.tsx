import { Head, Link, router, usePage } from '@inertiajs/react';
import { History, RefreshCw, UserMinus, UserPlus } from 'lucide-react';
import { useState } from 'react';
import {
    FbCompactTable,
    FbDetailCard,
    FbDetailPage,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import {
    VehicleDriverAssignmentModal,
    VehicleDriverRemoveModal,
} from '@/components/masters/vehicle-driver-assignment-modal';
import type { PartyOption } from '@/components/searchable-party-select';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDisplayDate } from '@/lib/dates';

type VehicleDetail = {
    id: string;
    registration_number: string;
    vehicle_type: string | null;
    make: string | null;
    model: string | null;
    manufacture_year: number | null;
    chassis_number: string | null;
    engine_number: string | null;
    capacity_mt: string | number | null;
    is_active: boolean;
    ownership: string | null;
    truck_type_name: string | null;
    tyre_layout_name: string | null;
    owner_party_name: string | null;
    primary_driver_name: string | null;
};

type DriverManagement = {
    can_manage: boolean;
    can_create_party: boolean;
    active_assignment: {
        id: string;
        driver_party_id: string;
        driver_name: string | null;
        driver_contact_number: string | null;
        assigned_from: string;
        assigned_to: string | null;
    } | null;
    history: Array<{
        id: string;
        driver_name: string;
        assigned_from: string;
        assigned_to: string | null;
        status: 'active' | 'scheduled' | 'ended';
        remarks: string | null;
    }>;
    driver_options: PartyOption[];
} | null;

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_edit: boolean; can_delete: boolean };
    vehicle: VehicleDetail;
    driverManagement?: DriverManagement;
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: {
        hub: string;
        dashboard: string;
        index: string;
        edit: string;
        destroy: string;
        documents: string;
        driverAssign?: string | null;
        driverRemove?: string | null;
        driverHistory?: string | null;
        vehicleDriversIndex?: string | null;
        partiesOptionsUrl?: string;
        partiesStoreUrl?: string;
    };
};

function humanizeVehicleType(value: string | null): string {
    if (!value) {
        return '—';
    }
    const normalized = value.trim().toLowerCase();
    if (normalized === 'truck') {
        return 'Truck';
    }
    if (normalized === 'trailer') {
        return 'Trailer';
    }
    return value;
}

function ownershipLabel(value: string | null): string {
    if (!value) {
        return '—';
    }
    if (value === 'own') {
        return 'Own Vehicle';
    }
    if (value === 'market') {
        return 'Market Vehicle';
    }
    return value;
}

function formatCapacity(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }
    return `${value} MT`;
}

export default function VehicleShow({
    permissions,
    vehicle,
    driverManagement = null,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);
    const [assignModalOpen, setAssignModalOpen] = useState(false);
    const [removeModalOpen, setRemoveModalOpen] = useState(false);
    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title={vehicle.registration_number} />
            <FbDetailPage>
                {flash?.success ? (
                    <p
                        role="status"
                        className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FbDetailCard
                    title={vehicle.registration_number}
                    badges={
                        <WorkspaceStatusBadge
                            status={vehicle.is_active ? 'Active' : 'Inactive'}
                        />
                    }
                    actions={
                        <>
                            {permissions.can_edit ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.edit}>Edit</Link>
                                </Button>
                            ) : null}
                            {documentDrawer ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setDocsOpen(true)}
                                >
                                    Documents
                                </Button>
                            ) : (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.documents}>
                                        Documents
                                    </Link>
                                </Button>
                            )}
                            {permissions.can_delete ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => {
                                        const remark = window.prompt(
                                            'Remark for soft deletion',
                                        );
                                        if (!remark) return;
                                        router.delete(links.destroy, {
                                            data: { remark },
                                        });
                                    }}
                                >
                                    Soft delete
                                </Button>
                            ) : null}
                        </>
                    }
                >
                    <FbInfoGrid>
                        <FbInfoItem
                            label="Vehicle type"
                            value={humanizeVehicleType(vehicle.vehicle_type)}
                        />
                        <FbInfoItem
                            label="Ownership"
                            value={ownershipLabel(vehicle.ownership)}
                        />
                        <FbInfoItem
                            label="Truck type"
                            value={vehicle.truck_type_name}
                        />
                        <FbInfoItem
                            label="Tyre layout"
                            value={vehicle.tyre_layout_name}
                        />
                        <FbInfoItem
                            label="Vehicle owner"
                            value={vehicle.owner_party_name}
                        />
                        <FbInfoItem
                            label="Primary driver"
                            value={
                                driverManagement?.active_assignment
                                    ?.driver_name ??
                                vehicle.primary_driver_name ??
                                (vehicle.ownership === 'own'
                                    ? 'Unassigned'
                                    : '—')
                            }
                        />
                        <FbInfoItem label="Make" value={vehicle.make} />
                        <FbInfoItem label="Model" value={vehicle.model} />
                        <FbInfoItem
                            label="Year"
                            value={
                                vehicle.manufacture_year !== null
                                    ? String(vehicle.manufacture_year)
                                    : '—'
                            }
                        />
                        <FbInfoItem
                            label="Capacity"
                            value={formatCapacity(vehicle.capacity_mt)}
                        />
                        <FbInfoItem
                            label="Chassis"
                            value={vehicle.chassis_number}
                        />
                        <FbInfoItem
                            label="Engine"
                            value={vehicle.engine_number}
                        />
                    </FbInfoGrid>
                </FbDetailCard>

                {vehicle.ownership === 'own' && driverManagement ? (
                    <div className="mt-4 space-y-4">
                        <FbDetailCard
                            title="Primary Driver Assignment"
                            actions={
                                <>
                                    {driverManagement.can_manage &&
                                    links.driverAssign ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="primary"
                                            className="gap-1.5"
                                            onClick={() =>
                                                setAssignModalOpen(true)
                                            }
                                        >
                                            {driverManagement.active_assignment ? (
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
                                    {driverManagement.can_manage &&
                                    driverManagement.active_assignment &&
                                    links.driverRemove ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="gap-1.5 text-destructive hover:text-destructive"
                                            onClick={() =>
                                                setRemoveModalOpen(true)
                                            }
                                        >
                                            <UserMinus className="size-3.5" />
                                            Remove Driver
                                        </Button>
                                    ) : null}
                                    {links.driverHistory ? (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={links.driverHistory}>
                                                <History className="size-3.5" />
                                                Full History
                                            </Link>
                                        </Button>
                                    ) : null}
                                </>
                            }
                        >
                            <FbInfoGrid>
                                <FbInfoItem
                                    label="Primary driver"
                                    value={
                                        driverManagement.active_assignment
                                            ?.driver_name ??
                                        vehicle.primary_driver_name ??
                                        'Unassigned'
                                    }
                                />
                                <FbInfoItem
                                    label="Driver contact"
                                    value={
                                        driverManagement.active_assignment
                                            ?.driver_contact_number ?? '—'
                                    }
                                />
                                <FbInfoItem
                                    label="Assignment date"
                                    value={
                                        driverManagement.active_assignment
                                            ?.assigned_from
                                            ? formatDisplayDate(
                                                  driverManagement
                                                      .active_assignment
                                                      .assigned_from,
                                              )
                                            : '—'
                                    }
                                />
                            </FbInfoGrid>
                        </FbDetailCard>

                        <FbCompactTable
                            title="Recent Driver Assignments"
                            count={driverManagement.history.length}
                            headers={[
                                'Driver',
                                'Effective From',
                                'Effective To',
                                'Status',
                                'Remarks',
                            ]}
                            empty="No driver assignment history recorded yet."
                        >
                            {driverManagement.history.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-t border-slate-100"
                                >
                                    <td className="px-3 py-2 font-medium">
                                        {row.driver_name}
                                    </td>
                                    <td className="px-3 py-2">
                                        {formatDisplayDate(row.assigned_from)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.assigned_to
                                            ? formatDisplayDate(row.assigned_to)
                                            : 'Ongoing'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Badge
                                            variant={
                                                row.status === 'active'
                                                    ? 'secondary'
                                                    : row.status === 'scheduled'
                                                      ? 'default'
                                                      : 'outline'
                                            }
                                        >
                                            {row.status === 'active'
                                                ? 'Active'
                                                : row.status === 'scheduled'
                                                  ? 'Scheduled'
                                                  : 'Ended'}
                                        </Badge>
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.remarks ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </FbCompactTable>
                    </div>
                ) : null}
            </FbDetailPage>

            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    {...documentDrawer}
                />
            ) : null}

            {driverManagement?.can_manage ? (
                <>
                    <VehicleDriverAssignmentModal
                        open={assignModalOpen}
                        onOpenChange={setAssignModalOpen}
                        fixedVehicle={{
                            id: vehicle.id,
                            registration_number: vehicle.registration_number,
                            current_driver_name:
                                driverManagement.active_assignment
                                    ?.driver_name ??
                                vehicle.primary_driver_name,
                        }}
                        driverOptions={driverManagement.driver_options}
                        partiesOptionsUrl={links.partiesOptionsUrl}
                        partiesStoreUrl={links.partiesStoreUrl}
                        canCreateParty={driverManagement.can_create_party}
                        defaultDate={today}
                        mode={
                            driverManagement.active_assignment
                                ? 'change'
                                : 'assign'
                        }
                    />
                    {driverManagement.active_assignment &&
                    links.driverRemove ? (
                        <VehicleDriverRemoveModal
                            open={removeModalOpen}
                            onOpenChange={setRemoveModalOpen}
                            removeUrl={links.driverRemove}
                            registrationNumber={vehicle.registration_number}
                            driverName={
                                driverManagement.active_assignment
                                    .driver_name ??
                                vehicle.primary_driver_name ??
                                'Primary Driver'
                            }
                            defaultDate={today}
                        />
                    ) : null}
                </>
            ) : null}
        </>
    );
}
