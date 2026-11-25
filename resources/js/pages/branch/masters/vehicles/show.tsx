import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    FbDetailCard,
    FbDetailPage,
    FbInfoGrid,
    FbInfoItem,
} from '@/components/fb-detail';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';

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

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_edit: boolean; can_delete: boolean };
    vehicle: VehicleDetail;
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
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);

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
                            value={vehicle.primary_driver_name}
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
            </FbDetailPage>
            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    {...documentDrawer}
                />
            ) : null}
        </>
    );
}
