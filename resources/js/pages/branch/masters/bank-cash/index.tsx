import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: string;
    name: string;
    account_kind: string;
    bank_name: string | null;
    account_number_masked: string | null;
    is_active: boolean;
    branch_id: string | null;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_create: boolean };
    filters: { q: string; kind: string };
    accounts: {
        data: Row[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { hub: string; index: string; create: string };
};

export default function BankCashIndex({
    company,
    branch,
    permissions,
    filters,
    accounts,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const [kind, setKind] = useState(filters.kind);
    const showUrl = (id: string) => `/branch/bank-cash/${id}`;

    const columns = useMemo<DataTableColumn<Row>[]>(
        () => [
            {
                id: 'name',
                header: 'Name',
                priority: 0,
                cell: (row) => (
                    <Link
                        href={showUrl(row.id)}
                        className="font-medium hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (row.is_active ? 'Active' : 'Inactive'),
            },
            {
                id: 'kind',
                header: 'Kind',
                priority: 2,
                cell: (row) => row.account_kind,
            },
            {
                id: 'bank',
                header: 'Bank',
                priority: 2,
                cell: (row) => row.bank_name || '—',
            },
            {
                id: 'account',
                header: 'Account',
                priority: 3,
                className: 'font-mono',
                cell: (row) => row.account_number_masked || '—',
            },
            {
                id: 'scope',
                header: 'Scope',
                priority: 3,
                cell: (row) => (row.branch_id ? 'branch' : 'shared'),
            },
        ],
        [company.id, branch.id],
    );

    return (
        <>
            <Head title={`Bank / Cash · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Bank / Cash"
                    title="Bank / Cash accounts"
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Bank / Cash' },
                    ]}
                />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                links.index,
                                { q: q || undefined, kind: kind || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="flex w-full flex-col gap-3 md:max-w-2xl md:flex-row"
                    >
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <div className="space-y-2 md:w-40">
                            <Label htmlFor="kind">Kind</Label>
                            <select
                                id="kind"
                                value={kind}
                                onChange={(e) => setKind(e.target.value)}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">All</option>
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </form>
                    {permissions.can_create ? (
                        <Button asChild>
                            <Link href={links.create}>Create account</Link>
                        </Button>
                    ) : null}
                </div>
                <ResponsiveDataTable
                    caption="Bank and cash accounts"
                    columns={columns}
                    rows={accounts.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No accounts"
                    emptyDescription="Create a bank or cash account."
                />
            </div>
        </>
    );
}
