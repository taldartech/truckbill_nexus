import { Form, Head, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { useMemo } from 'react';
import { PortalFlashBanner } from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection, PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type IdentityRow = {
    id: number;
    name: string;
    email: string;
    identity_type: string | null;
    is_super_admin: boolean;
    is_active: boolean;
    deactivated_at: string | null;
    show_url: string;
};

type Props = {
    filters: { q: string; status: string; type: string };
    index_url: string;
    identities: {
        data: IdentityRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function AdminIdentitiesIndex({
    filters,
    index_url,
    identities,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<IdentityRow>[]>(
        () => [
            {
                id: 'identity',
                header: 'Identity',
                priority: 0,
                cell: (row) => (
                    <>
                        <div className="font-medium">{row.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.email}
                        </div>
                    </>
                ),
            },
            {
                id: 'type',
                header: 'Type',
                priority: 2,
                cell: (row) =>
                    `${row.identity_type ?? '—'}${row.is_super_admin ? ' · Super Admin' : ''}`,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Identity lifecycle" />
            <PageShell
                title="Identities"
                description="Deactivate or reactivate Customer and Internal identities. Hard delete and restore are prohibited."
            >
                <PortalFlashBanner message={flash?.success} />

                <FormSection title="Search & filters" columns={2}>
                    <Form action={index_url} method="get" className="contents">
                        <div className="space-y-2 md:col-span-2 lg:col-span-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Name or email"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                defaultValue={filters.status}
                                className={selectClass}
                            >
                                <option value="all">All</option>
                                <option value="active">Active</option>
                                <option value="deactivated">Deactivated</option>
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="type">Type</Label>
                            <select
                                id="type"
                                name="type"
                                defaultValue={filters.type}
                                className={selectClass}
                            >
                                <option value="all">All</option>
                                <option value="customer">Customer</option>
                                <option value="internal">Internal</option>
                            </select>
                        </div>
                        <div className="flex items-end md:col-span-2">
                            <Button type="submit" variant="info">
                                Apply filters
                            </Button>
                        </div>
                    </Form>
                </FormSection>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Identities"
                        columns={columns}
                        rows={identities.data}
                        rowKey={(row) => String(row.id)}
                        emptyTitle="No identities match these filters"
                        emptyDescription="Try adjusting search or filter options."
                        actions={(identity) => (
                            <AppIconAction
                                icon={Eye}
                                label="View identity"
                                href={identity.show_url}
                                variant="info"
                            />
                        )}
                    />
                    <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                        Showing page {identities.meta.current_page} of{' '}
                        {identities.meta.last_page} ({identities.meta.total}{' '}
                        total)
                    </p>
                </div>
            </PageShell>
        </>
    );
}
