import { Head, router, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { MasterAuditRemarkDialog } from '@/components/masters/master-audit-remark-dialog';
import { QuickCreateStationSheet } from '@/components/masters/quick-create-station-sheet';
import { TablePagination } from '@/components/table-pagination';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: string;
    name: string;
    city: string | null;
    state: string | null;
    is_active: boolean;
};
type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    canManageOwnerStations: boolean;
    filters: { q: string };
    stations: {
        data: Row[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
        };
    };
    links: { hub: string; index: string; store: string };
};

function geographyLabel(row: Row): string {
    const parts = [row.city, row.state].filter(
        (part): part is string =>
            typeof part === 'string' && part.trim() !== '',
    );
    return parts.length > 0 ? parts.join(', ') : '—';
}

export default function StationsIndex({
    branch,
    canManageOwnerStations,
    filters,
    stations,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [createOpen, setCreateOpen] = useState(false);
    const [deleteRow, setDeleteRow] = useState<Row | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const showUrl = (id: string) => `/stations/${id}`;
    const editUrl = (id: string) => `/stations/${id}/edit`;

    return (
        <>
            <Head title={`Stations · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title="Stations"
                    toolbar={
                        canManageOwnerStations ? (
                            <Button
                                type="button"
                                className="app-index-header-btn h-8 gap-1.5 border-0 bg-white/15 px-3 text-white hover:bg-white/25"
                                onClick={() => setCreateOpen(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add station
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
                            const form = event.currentTarget;
                            const q = (
                                form.elements.namedItem('q') as HTMLInputElement
                            ).value.trim();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Name, city, or state"
                                className="min-w-[12rem]"
                            />
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    {!canManageOwnerStations ? (
                        <p className="border-b border-[#e5e7eb] px-4 py-2 text-sm text-slate-500">
                            Station creation is owner-only. Branch members can
                            browse the shared directory.
                        </p>
                    ) : null}

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Geography</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {stations.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={4}>
                                            <p className="app-index-empty">
                                                No stations yet.
                                                {canManageOwnerStations
                                                    ? ' Use Add station to create one.'
                                                    : null}
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    stations.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.name}
                                            </td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={
                                                        row.is_active
                                                            ? 'Active'
                                                            : 'Inactive'
                                                    }
                                                />
                                            </td>
                                            <td>{geographyLabel(row)}</td>
                                            <td>
                                                <div className="flex items-center justify-center gap-1">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="View station"
                                                        href={showUrl(row.id)}
                                                        variant="info"
                                                    />
                                                    {canManageOwnerStations ? (
                                                        <>
                                                            <AppIconAction
                                                                icon={Pencil}
                                                                label="Edit station"
                                                                href={editUrl(
                                                                    row.id,
                                                                )}
                                                                variant="warning"
                                                            />
                                                            <AppIconAction
                                                                icon={Trash2}
                                                                label="Delete station"
                                                                type="button"
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    setDeleteRow(
                                                                        row,
                                                                    )
                                                                }
                                                            />
                                                        </>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={links.index}
                            meta={stations.meta}
                            query={{ q: filters.q || undefined }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            {canManageOwnerStations ? (
                <>
                    <QuickCreateStationSheet
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                        storeUrl={links.store}
                        showActiveToggle
                        onCreated={() => {
                            router.reload({ only: ['stations', 'flash'] });
                        }}
                    />
                    <MasterAuditRemarkDialog
                        open={deleteRow !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setDeleteRow(null);
                                setDeleteProcessing(false);
                            }
                        }}
                        title="Delete station?"
                        description="Soft-delete this station from the shared directory."
                        confirmLabel="Delete"
                        processing={deleteProcessing}
                        onConfirm={(remark) => {
                            if (!deleteRow) {
                                return;
                            }
                            setDeleteProcessing(true);
                            router.delete(`/stations/${deleteRow.id}`, {
                                data: { remark },
                                preserveScroll: true,
                                onFinish: () => {
                                    setDeleteProcessing(false);
                                    setDeleteRow(null);
                                },
                            });
                        }}
                    />
                </>
            ) : null}
        </>
    );
}
