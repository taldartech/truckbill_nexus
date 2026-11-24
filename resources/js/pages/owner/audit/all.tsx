import { Head, Link, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDisplayDateSmart } from '@/lib/dates';

type Entry = {
    id: number;
    uuid: string;
    action: string;
    subject_type: string;
    subject_id: string | null;
    remark: string | null;
    created_at: string | null;
    company: { id: string; name: string };
    company_audit_url: string;
};

type Props = {
    filters: { company: string };
    companyOptions: { id: string; name: string }[];
    entries: {
        data: Entry[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { index: string; companies: string };
};

export default function OwnerAuditAll({
    filters,
    companyOptions,
    entries,
    links,
}: Props) {
    const [company, setCompany] = useState(filters.company);

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            { company: company || undefined },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<Entry>[]>(
        () => [
            {
                id: 'when',
                header: 'When',
                priority: 0,
                className: 'text-xs text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.created_at),
            },
            {
                id: 'action',
                header: 'Action',
                priority: 1,
                cell: (row) => row.action,
            },
            {
                id: 'company',
                header: 'Company',
                priority: 2,
                cell: (row) => row.company.name,
            },
            {
                id: 'subject',
                header: 'Subject',
                priority: 2,
                cell: (row) => row.subject_type,
            },
            {
                id: 'remark',
                header: 'Remark',
                priority: 3,
                className: 'max-w-xs truncate text-muted-foreground',
                cell: (row) => row.remark ?? '—',
            },
        ],
        [],
    );

    return (
        <>
            <Head title="Owner · Audit" />
            <OwnerPageHeader
                title="Audit"
                description="Recent audited actions across your owned companies. Open a company audit for filtered history."
                actions={
                    <Button asChild variant="outline">
                        <Link href={links.companies}>Companies</Link>
                    </Button>
                }
            >
                <form
                    onSubmit={applyFilters}
                    className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-end"
                >
                    <div className="min-w-0 flex-1 space-y-2">
                        <Label htmlFor="company">Company</Label>
                        <select
                            id="company"
                            value={company}
                            onChange={(event) => setCompany(event.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="">All owned companies</option>
                            {companyOptions.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit" variant="secondary">
                        Apply
                    </Button>
                </form>

                <ResponsiveDataTable
                    caption="Audit entries"
                    columns={columns}
                    rows={entries.data}
                    rowKey={(row) => row.uuid || String(row.id)}
                    emptyTitle="No audit entries"
                    emptyDescription="Audited Owner actions will appear here."
                    actions={(entry) => (
                        <AppIconAction
                            icon={Building2}
                            label="Open company audit"
                            href={entry.company_audit_url}
                            variant="info"
                        />
                    )}
                />

                <p className="text-xs text-muted-foreground">
                    Page {entries.meta.current_page} of {entries.meta.last_page}{' '}
                    · {entries.meta.total} total
                </p>
            </OwnerPageHeader>
        </>
    );
}
