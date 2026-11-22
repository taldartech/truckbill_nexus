import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Row = {
    id: string;
    code: string;
    name: string;
    city: string | null;
    state: string | null;
    is_active: boolean;
};
type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    canManageOwnerStations: boolean;
    filters: { q: string };
    stations: { data: Row[]; meta: { total: number } };
    links: { hub: string; index: string; create: string };
};
export default function StationsIndex({
    company,
    branch,
    canManageOwnerStations,
    filters,
    stations,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const showUrl = (id: string) =>
        `/branch/companies/${company.id}/branches/${branch.id}/stations/${id}`;
    return (
        <>
            <Head title={`Stations · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Stations"
                    title="Stations"
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Stations' },
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
                    {canManageOwnerStations ? (
                        <Button asChild>
                            <Link href={links.create}>Create station</Link>
                        </Button>
                    ) : null}
                </div>
                {!canManageOwnerStations ? (
                    <p className="text-sm text-muted-foreground">
                        Station creation is owner-only. Branch members can
                        browse the shared directory.
                    </p>
                ) : null}
                {stations.data.length === 0 ? (
                    <ShellEmptyState
                        title="No stations"
                        description="Owner-shared stations will appear here."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full min-w-[560px] text-left text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Code
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        City
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {stations.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={showUrl(row.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {row.code}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.name}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.city || '—'}
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
