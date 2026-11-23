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

type PartyRow = {
    id: string;
    name: string;
    contact_number: string | null;
    email: string | null;
    city: string | null;
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
    dataVisibility?: { mode: string; label: string; message: string };
    links: { hub: string; dashboard: string; index: string; create: string };
};

export default function PartiesIndex({
    company,
    branch,
    permissions,
    filters,
    roleOptions,
    parties,
    dataVisibility,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const [role, setRole] = useState(filters.role);

    const partyShowUrl = (id: string) => `/branch/parties/${id}`;

    const applyFilters = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            links.index,
            { q: q || undefined, role: role || undefined },
            { preserveState: true, replace: true },
        );
    };

    const columns = useMemo<DataTableColumn<PartyRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Name',
                priority: 0,
                cell: (row) => (
                    <Link
                        href={partyShowUrl(row.id)}
                        className="font-medium hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                id: 'roles',
                header: 'Roles',
                priority: 1,
                cell: (row) => row.roles.join(', ') || '—',
            },
            {
                id: 'contact',
                header: 'Contact',
                priority: 2,
                className: 'text-muted-foreground',
                cell: (row) => row.contact_number || row.email || '—',
            },
            {
                id: 'city',
                header: 'City',
                priority: 2,
                className: 'text-muted-foreground',
                cell: (row) => row.city || '—',
            },
        ],
        [company.id, branch.id],
    );

    return (
        <>
            <Head title={`Parties · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Party Master"
                    title="Parties"
                    company={company}
                    branch={branch}
                    dataVisibility={
                        dataVisibility
                            ? {
                                  label: dataVisibility.label,
                                  message: dataVisibility.message,
                              }
                            : null
                    }
                    crumbs={[
                        { label: 'Branch', href: links.dashboard },
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

                <ResponsiveDataTable
                    caption="Parties"
                    columns={columns}
                    rows={parties.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No parties found"
                    emptyDescription="Create a party or adjust filters."
                />

                {parties.data.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Page {parties.meta.current_page} of{' '}
                        {parties.meta.last_page} · {parties.meta.total} total
                    </p>
                ) : null}
            </div>
        </>
    );
}
