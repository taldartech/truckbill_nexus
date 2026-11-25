import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
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
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page?: number;
        };
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

    const partyShowUrl = (id: string) => `/parties/${id}`;
    const partyEditUrl = (id: string) => `/parties/${id}/edit`;

    return (
        <>
            <Head title="Parties" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Parties"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={links.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create party
                            </Link>
                        ) : null
                    }
                >
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}

                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const q = (
                                form.elements.namedItem('q') as HTMLInputElement
                            ).value.trim();
                            const role = (
                                form.elements.namedItem(
                                    'role',
                                ) as HTMLSelectElement
                            ).value;
                            router.get(
                                links.index,
                                { q: q || undefined, role: role || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="party-q">Search</Label>
                            <Input
                                id="party-q"
                                name="q"
                                defaultValue={filters.q}
                                placeholder="Name, phone, GSTIN…"
                                className="min-w-[12rem]"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="party-role">Role</Label>
                            <select
                                id="party-role"
                                name="role"
                                defaultValue={filters.role}
                                className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
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
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Roles</th>
                                    <th>Contact</th>
                                    <th>City</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {parties.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={5}>
                                            <p className="app-index-empty">
                                                No parties found. Create a party
                                                or adjust filters.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    parties.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.name}
                                            </td>
                                            <td>
                                                {row.roles.join(', ') || '—'}
                                            </td>
                                            <td className="text-slate-500">
                                                {row.contact_number ||
                                                    row.email ||
                                                    '—'}
                                            </td>
                                            <td className="text-slate-500">
                                                {row.city || '—'}
                                            </td>
                                            <td>
                                                <div className="flex items-center justify-center gap-1">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="View party"
                                                        href={partyShowUrl(
                                                            row.id,
                                                        )}
                                                        variant="info"
                                                    />
                                                    <AppIconAction
                                                        icon={Pencil}
                                                        label="Edit party"
                                                        href={partyEditUrl(
                                                            row.id,
                                                        )}
                                                        variant="warning"
                                                        visible={
                                                            permissions.can_edit
                                                        }
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-[#e5e7eb] px-4 py-3">
                        <TablePagination
                            baseUrl={links.index}
                            meta={parties.meta}
                            query={{
                                q: filters.q || undefined,
                                role: filters.role || undefined,
                            }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
