import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type PartyRow = {
    id: string;
    name: string;
    contact_number: string | null;
    email: string | null;
    city: string | null;
    scope: string;
    roles: string[];
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: {
        can_create: boolean;
        can_edit: boolean;
        can_delete: boolean;
    };
    filters: { q: string; role: string };
    roleOptions: { value: string; label: string }[];
    parties: {
        data: PartyRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { hub: string; index: string; create: string };
};

export default function PartiesIndex({
    company,
    branch,
    permissions,
    filters,
    roleOptions,
    parties,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const [role, setRole] = useState(filters.role);

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            { q: q || undefined, role: role || undefined },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`Parties · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Party Master"
                    title="Parties"
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Parties' },
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
                        onSubmit={applyFilters}
                        className="flex w-full flex-col gap-3 md:max-w-2xl md:flex-row"
                    >
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="party-q">Search</Label>
                            <Input
                                id="party-q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Name, phone, GSTIN…"
                            />
                        </div>
                        <div className="space-y-2 md:w-48">
                            <Label htmlFor="party-role">Role</Label>
                            <select
                                id="party-role"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={role}
                                onChange={(e) => setRole(e.target.value)}
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
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </form>
                    {permissions.can_create ? (
                        <Button asChild>
                            <Link href={links.create}>Create party</Link>
                        </Button>
                    ) : null}
                </div>

                {parties.data.length === 0 ? (
                    <ShellEmptyState
                        title="No parties found"
                        description="Create a party or adjust filters."
                    />
                ) : (
                    <>
                        <div className="grid gap-3 md:hidden">
                            {parties.data.map((party) => (
                                <Link
                                    key={party.id}
                                    href={`/branch/companies/${company.id}/branches/${branch.id}/parties/${party.id}`}
                                    className="rounded-xl border border-border p-4"
                                >
                                    <p className="font-medium">{party.name}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {party.scope} ·{' '}
                                        {party.roles.join(', ') || 'No roles'}
                                    </p>
                                </Link>
                            ))}
                        </div>
                        <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
                            <table className="w-full min-w-[640px] text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            Name
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Contact
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Scope
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Roles
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {parties.data.map((party) => (
                                        <tr
                                            key={party.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/branch/companies/${company.id}/branches/${branch.id}/parties/${party.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {party.name}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {party.contact_number ||
                                                    party.email ||
                                                    '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {party.scope}
                                            </td>
                                            <td className="px-4 py-3">
                                                {party.roles.join(', ') || '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Page {parties.meta.current_page} of{' '}
                            {parties.meta.last_page} · {parties.meta.total}{' '}
                            total
                        </p>
                    </>
                )}
            </div>
        </>
    );
}
