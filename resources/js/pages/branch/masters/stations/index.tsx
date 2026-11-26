import { Head, router, usePage } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { QuickCreateStationSheet } from '@/components/masters/quick-create-station-sheet';
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
    stations: { data: Row[]; meta: { total: number } };
    links: { hub: string; index: string; store: string };
};

function geographyLabel(row: Row): string {
    const parts = [row.city, row.state].filter(
        (part): part is string => typeof part === 'string' && part.trim() !== '',
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
    const showUrl = (id: string) => `/stations/${id}`;

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
                                className="app-index-header-btn"
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
                                    <th>Status</th>
                                    <th>Name</th>
                                    <th>Geography</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {stations.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={4}>
                                            <p className="app-index-empty">
                                                No stations. Owner-shared
                                                stations will appear here.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    stations.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={
                                                        row.is_active
                                                            ? 'Active'
                                                            : 'Inactive'
                                                    }
                                                />
                                            </td>
                                            <td className="font-medium">
                                                {row.name}
                                            </td>
                                            <td>{geographyLabel(row)}</td>
                                            <td>
                                                <div className="flex justify-center">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="View station"
                                                        href={showUrl(row.id)}
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
                </AppIndexListCard>
            </AppIndexPage>

            {canManageOwnerStations ? (
                <QuickCreateStationSheet
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                    storeUrl={links.store}
                    showActiveToggle
                    onCreated={() => {
                        router.reload({ only: ['stations', 'flash'] });
                    }}
                />
            ) : null}
        </>
    );
}