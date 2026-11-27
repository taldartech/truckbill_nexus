import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { MasterAuditRemarkDialog } from '@/components/masters/master-audit-remark-dialog';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type PartyRow = {
    id: string;
    name: string;
    contact_person: string | null;
    contact_number: string | null;
    gstin: string | null;
    city: string | null;
    state: string | null;
    status: 'active' | 'deleted';
    roles: string[];
};

type Option = { value: string; label: string };

type Filters = {
    q: string;
    role: string;
    state_id: string;
    city: string;
    status: 'active' | 'deleted';
};

type Props = {
    permissions: {
        can_create: boolean;
        can_edit: boolean;
        can_delete: boolean;
    };
    filters: Filters;
    roleOptions: Option[];
    stateOptions: Option[];
    parties: {
        data: PartyRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page?: number;
        };
    };
    links: { index: string; create: string };
};

const SELECT_CLASS =
    'h-9 min-w-[9rem] rounded-md border border-input bg-background px-3 text-sm';

function activeQuery(filters: Filters): Record<string, string | undefined> {
    return {
        q: filters.q || undefined,
        role: filters.role || undefined,
        state_id: filters.state_id || undefined,
        city: filters.city || undefined,
        status: filters.status === 'deleted' ? 'deleted' : undefined,
    };
}

export default function PartiesIndex({
    permissions,
    filters,
    roleOptions,
    stateOptions,
    parties,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [deleteRow, setDeleteRow] = useState<PartyRow | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const roleLabel = (role: string) =>
        roleOptions.find((option) => option.value === role)?.label ?? role;

    const closeDeleteDialog = () => {
        setDeleteRow(null);
        setDeleteError(null);
        setDeleteProcessing(false);
    };

    return (
        <>
            <Head title="Parties" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Parties"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={links.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create party
                            </Link>
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
                            const data = new FormData(event.currentTarget);
                            const read = (key: string) => {
                                const value = data.get(key);
                                return typeof value === 'string'
                                    ? value.trim()
                                    : '';
                            };
                            router.get(
                                links.index,
                                activeQuery({
                                    q: read('q'),
                                    role: read('role'),
                                    state_id: read('state_id'),
                                    city: read('city'),
                                    status:
                                        read('status') === 'deleted'
                                            ? 'deleted'
                                            : 'active',
                                }),
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="party-q">Search</Label>
                            <Input
                                id="party-q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Name, contact, phone, GSTIN, PAN"
                                className="min-w-[14rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="party-role">Role</Label>
                            <select
                                id="party-role"
                                name="role"
                                defaultValue={filters.role}
                                className={SELECT_CLASS}
                            >
                                <option value="">All roles</option>
                                {roleOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="party-state">State</Label>
                            <select
                                id="party-state"
                                name="state_id"
                                defaultValue={filters.state_id}
                                className={SELECT_CLASS}
                            >
                                <option value="">All states</option>
                                {stateOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="party-city">City</Label>
                            <Input
                                id="party-city"
                                name="city"
                                defaultValue={filters.city}
                                placeholder="City"
                                className="min-w-[9rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="party-status">Status</Label>
                            <select
                                id="party-status"
                                name="status"
                                defaultValue={filters.status}
                                className={SELECT_CLASS}
                            >
                                <option value="active">Active</option>
                                <option value="deleted">Deleted</option>
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
                                    <th>Party Name</th>
                                    <th>Roles</th>
                                    <th>Contact Number</th>
                                    <th>Location</th>
                                    <th>GSTIN</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {parties.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <p className="app-index-empty">
                                                No parties found. Create a party
                                                or adjust filters.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    parties.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>
                                                <div className="font-medium">
                                                    {row.name}
                                                </div>
                                                {row.contact_person ? (
                                                    <div className="text-xs text-slate-500">
                                                        {row.contact_person}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td>
                                                <div className="flex max-w-[14rem] flex-wrap gap-1">
                                                    {row.roles.map((role) => (
                                                        <Badge
                                                            key={role}
                                                            variant="secondary"
                                                            className="text-[0.7rem]"
                                                        >
                                                            {roleLabel(role)}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="text-slate-600">
                                                {row.contact_number || '—'}
                                            </td>
                                            <td className="text-slate-600">
                                                {[row.city, row.state]
                                                    .filter(Boolean)
                                                    .join(', ') || '—'}
                                            </td>
                                            <td className="font-mono text-xs text-slate-600">
                                                {row.gstin || '—'}
                                            </td>
                                            <td>
                                                <Badge
                                                    variant={
                                                        row.status === 'active'
                                                            ? 'outline'
                                                            : 'destructive'
                                                    }
                                                >
                                                    {row.status === 'active'
                                                        ? 'Active'
                                                        : 'Deleted'}
                                                </Badge>
                                            </td>
                                            <td>
                                                {row.status === 'active' ? (
                                                    <div className="flex items-center justify-center gap-1">
                                                        <AppIconAction
                                                            icon={Eye}
                                                            label="View party"
                                                            href={`/parties/${row.id}`}
                                                            variant="info"
                                                        />
                                                        <AppIconAction
                                                            icon={Pencil}
                                                            label="Edit party"
                                                            href={`/parties/${row.id}/edit`}
                                                            variant="warning"
                                                            visible={
                                                                permissions.can_edit
                                                            }
                                                        />
                                                        <AppIconAction
                                                            icon={Trash2}
                                                            label="Delete party"
                                                            type="button"
                                                            variant="destructive"
                                                            visible={
                                                                permissions.can_delete
                                                            }
                                                            onClick={() =>
                                                                setDeleteRow(
                                                                    row,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <p className="text-center text-xs text-slate-400">
                                                        —
                                                    </p>
                                                )}
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
                            meta={parties.meta}
                            query={activeQuery(filters)}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            <MasterAuditRemarkDialog
                open={deleteRow !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeDeleteDialog();
                    }
                }}
                title="Delete party?"
                description={deleteRow?.name ?? 'This party'}
                consequences={[
                    'The party is removed from active lists and selectors.',
                    'Existing Bilties, hires, invoices and audit history keep their party details.',
                ]}
                confirmLabel="Delete party"
                processing={deleteProcessing}
                errorMessage={deleteError}
                onConfirm={(remark) => {
                    if (!deleteRow) {
                        return;
                    }
                    setDeleteProcessing(true);
                    setDeleteError(null);
                    router.delete(`/parties/${deleteRow.id}`, {
                        data: { remark },
                        preserveScroll: true,
                        onSuccess: closeDeleteDialog,
                        onError: (errors) => {
                            setDeleteError(
                                Object.values(errors)[0] ??
                                    'The party could not be deleted.',
                            );
                        },
                        onFinish: () => setDeleteProcessing(false),
                    });
                }}
            />
        </>
    );
}
