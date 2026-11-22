import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: string;
    registration_number: string;
    vehicle_type: string | null;
    make: string | null;
    model: string | null;
    is_active: boolean;
    scope: string;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_create: boolean };
    filters: { q: string; active: string };
    vehicles: {
        data: Row[];
        meta: { current_page: number; last_page: number; total: number };
    };
    links: { hub: string; index: string; create: string };
};

export default function VehiclesIndex({
    company,
    branch,
    permissions,
    filters,
    vehicles,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const showUrl = (id: string) =>
        `/branch/companies/${company.id}/branches/${branch.id}/vehicles/${id}`;

    return (
        <>
            <Head title={`Vehicles · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Vehicle Master"
                    title="Vehicles"
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Vehicles' },
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
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="flex w-full gap-3 md:max-w-xl"
                    >
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </form>
                    {permissions.can_create ? (
                        <Button asChild>
                            <Link href={links.create}>Create vehicle</Link>
                        </Button>
                    ) : null}
                </div>
                {vehicles.data.length === 0 ? (
                    <ShellEmptyState
                        title="No vehicles"
                        description="Create a vehicle to get started."
                    />
                ) : (
                    <>
                        <div className="grid gap-3 md:hidden">
                            {vehicles.data.map((row) => (
                                <Link
                                    key={row.id}
                                    href={showUrl(row.id)}
                                    className="rounded-xl border border-border p-4"
                                >
                                    <p className="font-medium">
                                        {row.registration_number}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {row.make} {row.model} · {row.scope}
                                    </p>
                                </Link>
                            ))}
                        </div>
                        <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
                            <table className="w-full min-w-[640px] text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            Registration
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Make / model
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
                                    {vehicles.data.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-t border-border"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={showUrl(row.id)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.registration_number}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.vehicle_type || '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {[row.make, row.model]
                                                    .filter(Boolean)
                                                    .join(' ') || '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.scope}
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
                    </>
                )}
            </div>
        </>
    );
}
