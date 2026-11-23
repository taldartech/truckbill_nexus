import { Form, Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import InputError from '@/components/input-error';
import { MasterPageHeader } from '@/components/masters/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
    filters: { q: string; type: string };
    typeOptions: { value: string; label: string }[];
    items: { data: Item[]; meta: { total: number } };
    paymentModes: {
        id: string;
        code: string;
        name: string;
        is_active: boolean;
    }[];
    links: { hub: string; index: string; store: string };
};
export default function CataloguesIndex({
    company,
    branch,
    canManageOwnerCatalogues,
    filters,
    typeOptions,
    items,
    paymentModes,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [q, setQ] = useState(filters.q);
    const [type, setType] = useState(filters.type);

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
                cell: (row) => (row.is_active ? 'Active' : 'Inactive'),
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
            <Head title={`Supporting masters · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader title="Supporting masters" />
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            links.index,
                            { q: q || undefined, type },
                            { preserveState: true, replace: true },
                        );
                    }}
                    className="flex flex-col gap-3 md:flex-row md:items-end"
                >
                    <div className="space-y-2 md:w-56">
                        <Label htmlFor="type">Catalogue</Label>
                        <select
                            id="type"
                            value={type}
                            onChange={(e) => setType(e.target.value)}
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            {typeOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </div>
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
                {canManageOwnerCatalogues ? (
                    <Form
                        action={links.store}
                        method="post"
                        className="grid max-w-2xl gap-3 rounded-xl border border-border p-4 sm:grid-cols-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <h2 className="font-medium sm:col-span-2">
                                    Add catalogue item
                                </h2>
                                <input
                                    type="hidden"
                                    name="catalogue_type"
                                    value={type}
                                />
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
                        Owner-only create/edit. Payment modes below are
                        read-only.
                    </p>
                )}
                <ResponsiveDataTable
                    caption="Catalogue items"
                    columns={columns}
                    rows={items.data}
                    rowKey={(row) => row.id}
                    emptyTitle="No catalogue items"
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
                                              <Button
                                                  type="submit"
                                                  size="sm"
                                                  variant="outline"
                                                  disabled={processing}
                                              >
                                                  Delete
                                              </Button>
                                          </>
                                      )}
                                  </Form>
                              )
                            : undefined
                    }
                />
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        Payment modes (read-only)
                    </h2>
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {paymentModes.map((mode) => (
                            <li
                                key={mode.id}
                                className="rounded-xl border border-border p-3 text-sm"
                            >
                                <p className="font-medium">{mode.name}</p>
                                <p className="text-muted-foreground">
                                    {mode.code} ·{' '}
                                    {mode.is_active ? 'Active' : 'Inactive'}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
