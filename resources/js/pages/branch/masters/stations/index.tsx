import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Filter, Plus } from 'lucide-react';
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
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

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
    branch,
    canManageOwnerStations,
    filters,
    stations,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const showUrl = (id: string) => `/stations/${id}`;

    const columns = useMemo<DataTableColumn<Row>[]>(
        () => [
            {
                id: 'code',
                header: 'Code',
                priority: 0,
                cell: (row) => (
                    <Link
                        href={showUrl(row.id)}
                        className="font-medium hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'Active' : 'Inactive'}
                    />
                ),
            },
            {
                id: 'name',
                header: 'Name',
                priority: 2,
                cell: (row) => row.name,
            },
            {
                id: 'city',
                header: 'City',
                priority: 2,
                cell: (row) => row.city || '—',
            },
        ],
        [],
    );

    return (
        <>
            <Head title={`Stations · ${branch.name}`} />
            <PageShell
                title="Stations"
                description="Owner-shared station directory for routes and LR parties."
                actions={
                    canManageOwnerStations ? (
                        <Button asChild variant="primary" className="gap-2">
                            <Link href={links.create}>
                                <Plus className="size-4" />
                                Create station
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

                <FormSection title="Filters" columns={2}>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="contents"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
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

                {!canManageOwnerStations ? (
                    <p className="text-sm text-muted-foreground">
                        Station creation is owner-only. Branch members can
                        browse the shared directory.
                    </p>
                ) : null}

                <ResponsiveDataTable
                    caption="Stations"
                    columns={columns}
                    rows={stations.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No stations"
                    emptyDescription="Owner-shared stations will appear here."
                    actions={(row) => (
                        <AppIconAction
                            icon={Eye}
                            label="View station"
                            href={showUrl(row.id)}
                            variant="info"
                        />
                    )}
                />
            </PageShell>
        </>
    );
}
