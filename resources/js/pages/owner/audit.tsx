import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDisplayDateSmart } from '@/lib/dates';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type AuditEntryRow = {
    id: number;
    uuid: string;
    action: string;
    subject_type: string;
    subject_id: string;
    remark: string | null;
    actor_type: string;
    actor_id: string | null;
    is_owner_scope: boolean;
    created_at: string | null;
};

type OwnerAuditProps = {
    company: { id: string; name: string; status: string };
    ownerPanelScope: string;
    filters: { action: string; subject_type: string };
    filterOptions: {
        actions: string[];
        subject_types: string[];
    };
    entries: {
        data: AuditEntryRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: {
        dashboard: string;
        index: string;
    };
};

export default function OwnerAudit({
    company,
    ownerPanelScope,
    filters,
    filterOptions,
    entries,
    links,
}: OwnerAuditProps) {
    const [action, setAction] = useState(filters.action);
    const [subjectType, setSubjectType] = useState(filters.subject_type);

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            {
                action: action || undefined,
                subject_type: subjectType || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<AuditEntryRow>[]>(
        () => [
            {
                id: 'when',
                header: 'When',
                priority: 0,
                className: 'whitespace-nowrap text-muted-foreground',
                cell: (row) => formatDisplayDateSmart(row.created_at),
            },
            {
                id: 'action',
                header: 'Action',
                priority: 1,
                cell: (row) => row.action,
            },
            {
                id: 'subject',
                header: 'Subject',
                priority: 2,
                cell: (row) => (
                    <>
                        <div>{row.subject_type}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.subject_id}
                        </div>
                    </>
                ),
            },
            {
                id: 'remark',
                header: 'Remark',
                priority: 3,
                cell: (row) => row.remark ?? '—',
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Audit · ${company.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Owner Panel · Company audit
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {company.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <WorkspaceStatusBadge status={company.status} />
                            <span>Scope: {ownerPanelScope}</span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Read-only activity history. Audit records cannot be
                            edited or deleted.
                        </p>
                    </div>
                </div>

                <form
                    onSubmit={applyFilters}
                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="action">Action</Label>
                        <select
                            id="action"
                            value={action}
                            onChange={(event) => setAction(event.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                        >
                            <option value="">All actions</option>
                            {filterOptions.actions.map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="subject_type">Subject</Label>
                        <select
                            id="subject_type"
                            value={subjectType}
                            onChange={(event) =>
                                setSubjectType(event.target.value)
                            }
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                        >
                            <option value="">All subjects</option>
                            {filterOptions.subject_types.map((value) => (
                                <option key={value} value={value}>
                                    {value.split('\\').pop()}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <ResponsiveDataTable
                    caption="Company audit log"
                    columns={columns}
                    rows={entries.data}
                    rowKey={(row) => row.uuid}
                    emptyTitle="No audit entries"
                    emptyDescription="No company audit activity matches the current filters."
                />

                <p className="text-sm text-muted-foreground">
                    Page {entries.meta.current_page} of {entries.meta.last_page}{' '}
                    · {entries.meta.total} total
                </p>

                <div>
                    <Button asChild variant="secondary">
                        <Link href={links.dashboard}>Back to Owner Panel</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
