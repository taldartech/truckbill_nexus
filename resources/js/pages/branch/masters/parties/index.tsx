import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Filter, Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
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
    links: { hub: string; dashboard: string; index: string; create: string };
};

export default function PartiesIndex({
    permissions,
    filters,
    roleOptions,
    parties,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const [role, setRole] = useState(filters.role);

    const partyShowUrl = (id: string) => `/parties/${id}`;
    const partyEditUrl = (id: string) => `/parties/${id}/edit`;

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
                cell: (row) => <span className="font-medium">{row.name}</span>,
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
        [],
    );

    return (
        <>
            <Head title="Parties" />
            <PageShell
                title="Parties"
                description="Customers, vendors, transporters, and drivers."
                actions={
                    permissions.can_create ? (
                        <Button asChild variant="primary" className="gap-2">
                            <Link href={links.create}>
                                <Plus className="size-4" />
                                Create party
                            </Link>
                        </Button>
                    ) : null
                }
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <FormSection title="Filters" columns={3}>
                    <form onSubmit={applyFilters} className="contents">
                        <div className="space-y-2">
                            <Label htmlFor="party-q">Search</Label>
                            <Input
                                id="party-q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Name, phone, GSTIN…"
                            />
                        </div>
                        <div className="space-y-2">
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
                        <div className="flex items-end">
                            <Button
                                type="submit"
                                variant="secondary"
                                className="gap-2"
                            >
                                <Filter className="size-4" />
                                Apply
                            </Button>
                        </div>
                    </form>
                </FormSection>

                <ResponsiveDataTable
                    caption="Parties"
                    columns={columns}
                    rows={parties.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No parties found"
                    emptyDescription="Create a party or adjust filters."
                    actions={(row) => (
                        <div className="flex justify-end gap-1">
                            <AppIconAction
                                icon={Eye}
                                label="View party"
                                href={partyShowUrl(row.id)}
                                variant="info"
                            />
                            <AppIconAction
                                icon={Pencil}
                                label="Edit party"
                                href={partyEditUrl(row.id)}
                                variant="warning"
                                visible={permissions.can_edit}
                            />
                        </div>
                    )}
                />

                {parties.data.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Page {parties.meta.current_page} of{' '}
                        {parties.meta.last_page} · {parties.meta.total} total
                    </p>
                ) : null}
            </PageShell>
        </>
    );
}
