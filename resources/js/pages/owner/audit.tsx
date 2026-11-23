import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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

                {entries.data.length === 0 ? (
                    <ShellEmptyState
                        title="No audit entries"
                        description="No company audit activity matches the current filters."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/40">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        When
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Action
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Subject
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Remark
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => (
                                    <tr
                                        key={entry.uuid}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                            {entry.created_at
                                                ? new Date(
                                                      entry.created_at,
                                                  ).toLocaleString()
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.action}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div>{entry.subject_type}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {entry.subject_id}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.remark ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

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
