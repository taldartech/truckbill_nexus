import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { EditOwnerCatalogueSheet } from '@/components/masters/edit-owner-catalogue-sheet';
import { MasterAuditRemarkDialog } from '@/components/masters/master-audit-remark-dialog';
import { QuickCreateCatalogueSheet } from '@/components/masters/quick-create-catalogue-sheet';
import { TablePagination } from '@/components/table-pagination';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { addMasterActionLabel, singularMasterLabel } from '@/lib/master-labels';

type Item = {
    id: string;
    code?: string | null;
    name: string;
    is_active: boolean;
    sort_order: number;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    canManageOwnerCatalogues: boolean;
    catalogue: {
        type: string;
        title: string;
        description: string;
        uses_code?: boolean;
    };
    filters: { q: string };
    items: {
        data: Item[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
        };
    };
    links: { hub: string; index: string; store: string };
};

export default function CatalogueTypeIndex({
    branch,
    canManageOwnerCatalogues,
    catalogue,
    filters,
    items,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const usesCode = catalogue.uses_code ?? catalogue.type === 'document_type';
    const singularLabel = singularMasterLabel(catalogue.title);
    const addLabel = addMasterActionLabel(catalogue.title);

    const [createOpen, setCreateOpen] = useState(false);
    const [editItem, setEditItem] = useState<Item | null>(null);
    const [deleteItem, setDeleteItem] = useState<Item | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const colSpan = 2 + (usesCode ? 1 : 0) + (canManageOwnerCatalogues ? 1 : 0);

    return (
        <>
            <Head title={`${catalogue.title} · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={catalogue.title}
                    toolbar={
                        canManageOwnerCatalogues ? (
                            <Button
                                type="button"
                                className="app-index-header-btn h-8 gap-1.5 border-0 bg-white/15 px-3 text-white hover:bg-white/25"
                                onClick={() => setCreateOpen(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                {addLabel}
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
                        {catalogue.description}
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
                            Owner-only create, edit, and delete for shared
                            catalogue masters.
                        </p>
                    ) : null}

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    {usesCode ? <th>Code</th> : null}
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
                                                No {singularLabel} yet.
                                                {canManageOwnerCatalogues
                                                    ? ` Use ${addLabel} to add one.`
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
                                            {usesCode ? (
                                                <td className="font-mono">
                                                    {item.code ?? '—'}
                                                </td>
                                            ) : null}
                                            {canManageOwnerCatalogues ? (
                                                <td>
                                                    <div className="flex items-center justify-center gap-1">
                                                        <AppIconAction
                                                            icon={Pencil}
                                                            label={`Edit ${singularLabel}`}
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
                                                            label={`Delete ${singularLabel}`}
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
                    <QuickCreateCatalogueSheet
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                        title={singularLabel}
                        storeUrl={links.store}
                        usesCode={usesCode}
                        sheetDescription={`Add a ${singularLabel} to this register.`}
                        onCreated={() => {
                            router.reload();
                        }}
                    />
                    <EditOwnerCatalogueSheet
                        open={editItem !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setEditItem(null);
                            }
                        }}
                        pageTitle={catalogue.title}
                        item={editItem}
                        updateUrl={
                            editItem
                                ? `/catalogues/${editItem.id}`
                                : '/catalogues'
                        }
                        usesCode={usesCode}
                    />
                    <MasterAuditRemarkDialog
                        open={deleteItem !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setDeleteItem(null);
                                setDeleteProcessing(false);
                            }
                        }}
                        title={`Delete ${singularLabel}?`}
                        description={`Soft-delete this ${singularLabel} from the register.`}
                        confirmLabel="Delete"
                        processing={deleteProcessing}
                        onConfirm={(remark) => {
                            if (!deleteItem) {
                                return;
                            }
                            setDeleteProcessing(true);
                            router.delete(`/catalogues/${deleteItem.id}`, {
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
