import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
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
    const showUrl = (id: string) =>
        `/branch/companies/${company.id}/branches/${branch.id}/bank-cash/${id}`;
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
                {accounts.data.length === 0 ? (
                    <ShellEmptyState
                        title="No accounts"
                        description="Create a bank or cash account."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Kind
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Bank
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Account
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Scope
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={showUrl(row.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {row.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.account_kind}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.bank_name || '—'}
                                        </td>
                                        <td className="px-4 py-3 font-mono">
                                            {row.account_number_masked || '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.branch_id
                                                ? 'branch'
                                                : 'shared'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
