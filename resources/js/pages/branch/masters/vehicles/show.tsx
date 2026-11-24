import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { FormSection } from '@/components/form-section';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { PageShell } from '@/components/page-shell';
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

function DetailItem({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1 text-sm">{value}</dd>
        </div>
    );
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
            <PageShell title={vehicle.registration_number}>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {permissions.can_edit ? (
                        <Button asChild variant="outline">
                            <Link href={links.edit}>Edit</Link>
                        </Button>
                    ) : null}
                    {documentDrawer ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDocsOpen(true)}
                        >
                            Documents
                        </Button>
                    ) : (
                        <Button asChild variant="outline">
                            <Link href={links.documents}>Documents</Link>
                        </Button>
                    )}
                    {permissions.can_delete ? (
                        <Button
                            type="button"
                            variant="destructive"
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
                </div>

                <FormSection title="Identity & status" columns={2}>
                    <DetailItem
                        label="Truck No."
                        value={vehicle.registration_number}
                    />
                    <div>
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Status
                        </dt>
                        <dd className="mt-1">
                            <WorkspaceStatusBadge
                                status={
                                    vehicle.is_active ? 'Active' : 'Inactive'
                                }
                            />
                        </dd>
                    </div>
                    <DetailItem
                        label="Vehicle Type"
                        value={humanizeVehicleType(vehicle.vehicle_type)}
                    />
                    <DetailItem
                        label="Ownership"
                        value={ownershipLabel(vehicle.ownership)}
                    />
                </FormSection>

                <FormSection title="Classification" columns={2}>
                    <DetailItem
                        label="Truck Type"
                        value={vehicle.truck_type_name ?? '—'}
                    />
                    <DetailItem
                        label="Tyre Layout"
                        value={vehicle.tyre_layout_name ?? '—'}
                    />
                </FormSection>

                <FormSection title="People" columns={2}>
                    <DetailItem
                        label="Vehicle Owner"
                        value={vehicle.owner_party_name ?? '—'}
                    />
                    <DetailItem
                        label="Primary Driver"
                        value={vehicle.primary_driver_name ?? '—'}
                    />
                </FormSection>

                <FormSection title="Specifications" columns={2}>
                    <DetailItem label="Make" value={vehicle.make ?? '—'} />
                    <DetailItem label="Model" value={vehicle.model ?? '—'} />
                    <DetailItem
                        label="Year"
                        value={
                            vehicle.manufacture_year !== null
                                ? String(vehicle.manufacture_year)
                                : '—'
                        }
                    />
                    <DetailItem
                        label="Capacity"
                        value={formatCapacity(vehicle.capacity_mt)}
                    />
                    <DetailItem
                        label="Chassis"
                        value={vehicle.chassis_number ?? '—'}
                    />
                    <DetailItem
                        label="Engine"
                        value={vehicle.engine_number ?? '—'}
                    />
                </FormSection>
            </PageShell>
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
