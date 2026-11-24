import { Form, Head, router, usePage } from '@inertiajs/react';
import { Filter, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type Item = {
    id: string;
    code: string;
    name: string;
    is_active: boolean;
    sort_order: number;
};

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    canManageOwnerCatalogues: boolean;
    catalogue: {
        type: string;
        title: string;
        description: string;
    };
    filters: { q: string };
    items: { data: Item[]; meta: { total: number } };
    links: { hub: string; index: string; store: string };
};

export default function CatalogueTypeIndex({
    branch,
    canManageOwnerCatalogues,
    catalogue,
    filters,
    items,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);

    const columns = useMemo<DataTableColumn<Item>[]>(
        () => [
            {
                id: 'code',
                header: 'Code',
                priority: 0,
                className: 'font-mono',
                cell: (row) => row.code,
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
        ],
        [],
    );

    return (
        <>
            <Head title={`${catalogue.title} · ${branch.name}`} />
            <PageShell
                title={catalogue.title}
                description={catalogue.description}
            >
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <FormSection title="Search">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="flex flex-col gap-3 md:flex-row md:items-end"
                    >
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="secondary"
                            className="gap-2"
                        >
                            <Filter className="size-4" />
                            Apply
                        </Button>
                    </form>
                </FormSection>
                {canManageOwnerCatalogues ? (
                    <Form
                        action={links.store}
                        method="post"
                        className="grid max-w-2xl gap-3 rounded-xl border border-border p-4 sm:grid-cols-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <h2 className="font-medium sm:col-span-2">
                                    Add {catalogue.title.toLowerCase()} item
                                </h2>
                                <div className="space-y-2">
                                    <Label htmlFor="code">Code</Label>
                                    <Input id="code" name="code" required />
                                    <InputError message={errors.code} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input id="name" name="name" required />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="remark">Remark</Label>
                                    <Input
                                        id="remark"
                                        name="remark"
                                        defaultValue="Create catalogue item"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="sm:col-span-2"
                                >
                                    Create
                                </Button>
                            </>
                        )}
                    </Form>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Owner-only create and deactivate for shared catalogue
                        masters.
                    </p>
                )}
                <ResponsiveDataTable
                    caption={catalogue.title}
                    columns={columns}
                    rows={items.data}
                    rowKey={(row) => row.id}
                    emptyTitle={`No ${catalogue.title.toLowerCase()} yet`}
                    emptyDescription="Add an item using the form above."
                    actions={
                        canManageOwnerCatalogues
                            ? (item) => (
                                  <Form
                                      action={`/catalogues/${item.id}`}
                                      method="delete"
                                      className="inline"
                                  >
                                      {({ processing }) => (
                                          <>
                                              <input
                                                  type="hidden"
                                                  name="remark"
                                                  value="Delete catalogue item"
                                              />
                                              <AppIconAction
                                                  icon={Trash2}
                                                  label="Deactivate catalogue item"
                                                  type="submit"
                                                  disabled={processing}
                                                  variant="destructive"
                                              />
                                          </>
                                      )}
                                  </Form>
                              )
                            : undefined
                    }
                />
            </PageShell>
        </>
    );
}
