import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { EditTyreLayoutSheet } from '@/components/masters/edit-tyre-layout-sheet';
import { MasterAuditRemarkDialog } from '@/components/masters/master-audit-remark-dialog';
import { QuickCreateTyreLayoutSheet } from '@/components/masters/quick-create-tyre-layout-sheet';
import { TablePagination } from '@/components/table-pagination';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type LayoutItem = {
    id: string;
    name: string;
    spare_tyre_count: number;
    drive_tyre_count: number;
    total_tyre_count: number;
    needs_axle_completion: boolean;
    is_active: boolean;
    sort_order: number;
    axles: Array<{
        sequence: number;
        configuration: 'single' | 'dual';
        label: string;
    }>;
    positions: Array<{
        id: string;
        position_key: string;
        label: string;
        axle_sequence: number;
        side: string;
        ring: string | null;
    }>;
};

type Props = {
    branch: { id: string; name: string; status: string };
    canManageOwnerCatalogues: boolean;
    filters: { q: string };
    items: {
        data: LayoutItem[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
        };
    };
    links: { hub: string; index: string; store: string };
};

export default function TyreLayoutsIndex({
    branch,
    canManageOwnerCatalogues,
    filters,
    items,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [createOpen, setCreateOpen] = useState(false);
    const [editItem, setEditItem] = useState<LayoutItem | null>(null);
    const [deleteItem, setDeleteItem] = useState<LayoutItem | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const colSpan = canManageOwnerCatalogues ? 7 : 6;

    return (
        <>
            <Head title={`Tyre layouts · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title="Tyre layouts"
                    toolbar={
                        canManageOwnerCatalogues ? (
                            <Button
                                type="button"
                                className="app-index-header-btn h-8 gap-1.5 border-0 bg-white/15 px-3 text-white hover:bg-white/25"
                                onClick={() => setCreateOpen(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add tyre layout
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

                    <p className="border-b border-[#e5e7eb] px-4 py-2 text-sm text-slate-500">
                        Structured axle configuration for own-fleet vehicles.
                        Position identifiers are derived automatically. Historic
                        layouts may need axle completion.
                    </p>

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

                    {!canManageOwnerCatalogues ? (
                        <p className="border-b border-[#e5e7eb] px-4 py-2 text-sm text-slate-500">
                            Owner-only create, edit, and delete for tyre
                            layouts.
                        </p>
                    ) : null}

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Running</th>
                                    <th>Spare</th>
                                    <th>Total</th>
                                    <th>Notes</th>
                                    {canManageOwnerCatalogues ? (
                                        <th className="text-center">Actions</th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {items.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={colSpan}>
                                            <p className="app-index-empty">
                                                No tyre layouts yet.
                                                {canManageOwnerCatalogues
                                                    ? ' Use Add tyre layout to create one.'
                                                    : null}
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    items.data.map((item) => (
                                        <tr key={item.id}>
                                            <td className="font-medium">
                                                {item.name}
                                            </td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={
                                                        item.is_active
                                                            ? 'Active'
                                                            : 'Inactive'
                                                    }
                                                />
                                            </td>
                                            <td>{item.drive_tyre_count}</td>
                                            <td>{item.spare_tyre_count}</td>
                                            <td>{item.total_tyre_count}</td>
                                            <td className="text-slate-500">
                                                {item.needs_axle_completion
                                                    ? 'Needs axle completion'
                                                    : '—'}
                                            </td>
                                            {canManageOwnerCatalogues ? (
                                                <td>
                                                    <div className="flex items-center justify-center gap-1">
                                                        <AppIconAction
                                                            icon={Pencil}
                                                            label="Edit tyre layout"
                                                            type="button"
                                                            variant="warning"
                                                            onClick={() =>
                                                                setEditItem(
                                                                    item,
                                                                )
                                                            }
                                                        />
                                                        <AppIconAction
                                                            icon={Trash2}
                                                            label="Delete tyre layout"
                                                            type="button"
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setDeleteItem(
                                                                    item,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </td>
                                            ) : null}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={links.index}
                            meta={items.meta}
                            query={{ q: filters.q || undefined }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            {canManageOwnerCatalogues ? (
                <>
                    <QuickCreateTyreLayoutSheet
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                        storeUrl={links.store}
                        onCreated={() => {
                            router.reload();
                        }}
                    />
                    <EditTyreLayoutSheet
                        open={editItem !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setEditItem(null);
                            }
                        }}
                        item={editItem}
                        updateUrl={
                            editItem
                                ? `/tyre-layouts/${editItem.id}`
                                : '/tyre-layouts'
                        }
                    />
                    <MasterAuditRemarkDialog
                        open={deleteItem !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setDeleteItem(null);
                                setDeleteProcessing(false);
                            }
                        }}
                        title="Delete tyre layout?"
                        description="Soft-delete this tyre layout from the register."
                        confirmLabel="Delete"
                        processing={deleteProcessing}
                        onConfirm={(remark) => {
                            if (!deleteItem) {
                                return;
                            }
                            setDeleteProcessing(true);
                            router.delete(`/tyre-layouts/${deleteItem.id}`, {
                                data: { remark },
                                preserveScroll: true,
                                onFinish: () => {
                                    setDeleteProcessing(false);
                                    setDeleteItem(null);
                                },
                            });
                        }}
                    />
                </>
            ) : null}
        </>
    );
}
