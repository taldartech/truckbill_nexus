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

type CompanyRow = {
    id: string;
    name: string;
    pan: string | null;
    is_active: boolean;
    deactivated_at: string | null;
    owner: { id: number; name: string; email: string } | null;
    show_url: string;
};

type Props = {
    filters: { q: string; status: string };
    index_url: string;
    companies: {
        data: CompanyRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function AdminCompaniesIndex({
    filters,
    index_url,
    companies,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const columns = useMemo<DataTableColumn<CompanyRow>[]>(
        () => [
            {
                id: 'company',
                header: 'Company',
                priority: 0,
                cell: (row) => (
                    <>
                        <div className="font-medium">{row.name}</div>
                        {row.pan ? (
                            <div className="font-mono text-xs text-muted-foreground">
                                {row.pan}
                            </div>
                        ) : null}
                    </>
                ),
            },
            {
                id: 'owner',
                header: 'Owner',
                priority: 2,
                cell: (row) =>
                    row.owner ? (
                        <>
                            <div>{row.owner.name}</div>
                            <div className="text-xs text-muted-foreground">
                                {row.owner.email}
                            </div>
                        </>
                    ) : (
                        '—'
                    ),
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
            <Head title="Company lifecycle" />
            <PageShell
                title="Companies"
                description="Deactivate and reactivate companies with mandatory remarks. Ownership transfer is available on company detail."
            >
                <PortalFlashBanner message={flash?.success} />

                <FormSection title="Search & filters" columns={2}>
                    <Form action={index_url} method="get" className="contents">
                        <div className="space-y-2 md:col-span-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Name, PAN, or owner"
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
                        <div className="flex items-end md:col-span-2">
                            <Button type="submit" variant="info">
                                Apply filters
                            </Button>
                        </div>
                    </Form>
                </FormSection>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="Companies"
                        columns={columns}
                        rows={companies.data}
                        rowKey={(row) => row.id}
                        emptyTitle="No companies match these filters"
                        emptyDescription="Try adjusting search or status filters."
                        actions={(company) => (
                            <AppIconAction
                                icon={Eye}
                                label="View company"
                                href={company.show_url}
                                variant="info"
                            />
                        )}
                    />
                    <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                        Showing page {companies.meta.current_page} of{' '}
                        {companies.meta.last_page} ({companies.meta.total}{' '}
                        total)
                    </p>
                </div>
            </PageShell>
        </>
    );
}
