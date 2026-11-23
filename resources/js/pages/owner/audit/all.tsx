import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { OwnerPageHeader } from '@/components/owner/owner-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

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

    return (
        <>
            <Head title="Owner · Audit" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
                <OwnerPageHeader
                    title="Audit"
                    description="Recent audited actions across your owned companies. Open a company audit for filtered history."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={links.companies}>Companies</Link>
                        </Button>
                    }
                />

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

                {entries.data.length === 0 ? (
                    <ShellEmptyState
                        title="No audit entries"
                        description="Audited Owner actions will appear here."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border bg-card shadow-sm">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-border bg-muted/40 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        When
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Company
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
                                    <th className="px-3 py-2 font-medium">
                                        Open
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => (
                                    <tr
                                        key={entry.uuid || String(entry.id)}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-3 py-3 text-xs text-muted-foreground">
                                            {entry.created_at
                                                ? new Date(
                                                      entry.created_at,
                                                  ).toLocaleString()
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            {entry.company.name}
                                        </td>
                                        <td className="px-3 py-3 font-medium">
                                            {entry.action}
                                        </td>
                                        <td className="px-3 py-3">
                                            {entry.subject_type}
                                        </td>
                                        <td className="max-w-xs truncate px-3 py-3 text-muted-foreground">
                                            {entry.remark ?? '—'}
                                        </td>
                                        <td className="px-3 py-3">
                                            <Link
                                                href={entry.company_audit_url}
                                                className="text-xs font-medium text-primary underline"
                                            >
                                                Company audit
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    Page {entries.meta.current_page} of {entries.meta.last_page}{' '}
                    · {entries.meta.total} total
                </p>
            </div>
        </>
    );
}
