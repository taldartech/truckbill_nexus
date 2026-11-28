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

type DriverRow = {
    id: string;
    name: string;
    short_name: string | null;
    contact_number: string | null;
    driver_type: string | null;
    license_number: string | null;
    license_expiry: string | null;
    city: string | null;
    state: string | null;
    status: 'active' | 'deleted';
    urls: {
        show: string;
        edit: string;
        destroy: string;
    };
};

type Option = { value: string; label: string };

type Filters = {
    q: string;
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
    stateOptions: Option[];
    drivers: {
        data: DriverRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page?: number;
        };
    };
    links: {
        index: string;
        create: string;
        parties_index: string;
    };
};

const SELECT_CLASS =
    'h-9 min-w-[9rem] rounded-md border border-input bg-background px-3 text-sm';

function activeQuery(filters: Filters): Record<string, string | undefined> {
    return {
        q: filters.q || undefined,
        state_id: filters.state_id || undefined,
        city: filters.city || undefined,
        status: filters.status === 'deleted' ? 'deleted' : undefined,
    };
}

export default function DriversIndex({
    permissions,
    filters,
    stateOptions,
    drivers,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [deleteRow, setDeleteRow] = useState<DriverRow | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const closeDeleteDialog = () => {
        setDeleteRow(null);
        setDeleteError(null);
        setDeleteProcessing(false);
    };

    return (
        <>
            <Head title="Driver Master" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Driver Master"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={links.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add Driver
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
                            <Label htmlFor="driver-q">Search</Label>
                            <Input
                                id="driver-q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Driver name, short name, mobile"
                                className="min-w-[14rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="driver-state">State</Label>
                            <select
                                id="driver-state"
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
                            <Label htmlFor="driver-city">City</Label>
                            <Input
                                id="driver-city"
                                name="city"
                                defaultValue={filters.city}
                                placeholder="City"
                                className="min-w-[9rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="driver-status">Status</Label>
                            <select
                                id="driver-status"
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
                                    <th>Driver Name</th>
                                    <th>Short Name</th>
                                    <th>Mobile Number</th>
                                    <th>Driving Licence Number</th>
                                    <th>Licence Expiry</th>
                                    <th>City and State</th>
                                    <th>Status</th>
                                    <th className="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {drivers.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={8}>
                                            <p className="app-index-empty">
                                                No drivers found. Add a driver
                                                or adjust filters.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    drivers.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="max-w-[12rem] font-medium whitespace-normal">
                                                {row.name}
                                            </td>
                                            <td className="max-w-[9rem] whitespace-normal text-slate-600">
                                                {row.short_name || '—'}
                                            </td>
                                            <td className="text-slate-600 tabular-nums">
                                                {row.contact_number || '—'}
                                            </td>
                                            <td className="font-mono text-xs text-slate-700">
                                                {row.license_number || '—'}
                                            </td>
                                            <td className="text-slate-600 tabular-nums">
                                                {row.license_expiry || '—'}
                                            </td>
                                            <td className="max-w-[10rem] whitespace-normal text-slate-600">
                                                {[row.city, row.state]
                                                    .filter(Boolean)
                                                    .join(', ') || '—'}
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
                                                    <div className="flex items-center justify-end gap-1">
                                                        <AppIconAction
                                                            icon={Eye}
                                                            label="View driver"
                                                            href={row.urls.show}
                                                            variant="info"
                                                        />
                                                        <AppIconAction
                                                            icon={Pencil}
                                                            label="Edit driver"
                                                            href={row.urls.edit}
                                                            variant="warning"
                                                            visible={
                                                                permissions.can_edit
                                                            }
                                                        />
                                                        <AppIconAction
                                                            icon={Trash2}
                                                            label="Delete driver"
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
                                                    <p className="text-right text-xs text-slate-400">
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
                            meta={drivers.meta}
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
                title="Delete driver?"
                description={deleteRow?.name ?? 'This driver'}
                consequences={[
                    'The driver is removed from active lists and selectors.',
                    'Existing Bilties, Market Vehicles, hires and audit history retain the driver reference.',
                ]}
                confirmLabel="Delete driver"
                processing={deleteProcessing}
                errorMessage={deleteError}
                onConfirm={(remark) => {
                    if (!deleteRow) {
                        return;
                    }
                    setDeleteProcessing(true);
                    setDeleteError(null);
                    router.delete(deleteRow.urls.destroy, {
                        data: { remark, return_to: 'drivers' },
                        preserveScroll: true,
                        onSuccess: closeDeleteDialog,
                        onError: (errors) => {
                            setDeleteError(
                                Object.values(errors)[0] ??
                                    'The driver could not be deleted.',
                            );
                        },
                        onFinish: () => setDeleteProcessing(false),
                    });
                }}
            />
        </>
    );
}
