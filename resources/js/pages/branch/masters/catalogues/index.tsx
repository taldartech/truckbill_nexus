import { Form, Head, router, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
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
    branch,
    canManageOwnerCatalogues,
    filters,
    typeOptions,
    items,
    paymentModes,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;

    return (
        <>
            <Head title={`Supporting masters · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard title="Supporting masters">
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
                            const type = (
                                form.elements.namedItem(
                                    'type',
                                ) as HTMLSelectElement
                            ).value;
                            const q = (
                                form.elements.namedItem('q') as HTMLInputElement
                            ).value.trim();
                            router.get(
                                links.index,
                                { q: q || undefined, type },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="type">Catalogue</Label>
                            <select
                                id="type"
                                name="type"
                                defaultValue={filters.type}
                                className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {typeOptions.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                className="min-w-[12rem]"
                            />
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    {canManageOwnerCatalogues ? (
                        <Form
                            action={links.store}
                            method="post"
                            className="grid gap-3 border-b border-[#e5e7eb] bg-white px-4 py-4 sm:grid-cols-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <h3 className="text-sm font-semibold text-[#334155] sm:col-span-2">
                                        Add catalogue item
                                    </h3>
                                    <input
                                        type="hidden"
                                        name="catalogue_type"
                                        value={filters.type}
                                    />
                                    <div className="space-y-1">
                                        <Label htmlFor="code">Code</Label>
                                        <Input id="code" name="code" required />
                                        <InputError message={errors.code} />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="name">Name</Label>
                                        <Input id="name" name="name" required />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="space-y-1 sm:col-span-2">
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
                                        className="bg-[#2FB36F] text-white hover:bg-[#279960] sm:col-span-2 sm:w-fit"
                                    >
                                        Create
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="border-b border-[#e5e7eb] px-4 py-2 text-sm text-slate-500">
                            Owner-only create/edit. Payment modes below are
                            read-only.
                        </p>
                    )}

                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Status</th>
                                    <th>Name</th>
                                    {canManageOwnerCatalogues ? (
                                        <th className="text-center">Actions</th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {items.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                canManageOwnerCatalogues ? 4 : 3
                                            }
                                        >
                                            <p className="app-index-empty">
                                                No catalogue items. Add an item
                                                using the form above.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    items.data.map((item) => (
                                        <tr key={item.id}>
                                            <td className="font-mono">
                                                {item.code}
                                            </td>
                                            <td>
                                                {item.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </td>
                                            <td>{item.name}</td>
                                            {canManageOwnerCatalogues ? (
                                                <td>
                                                    <div className="flex justify-center">
                                                        <Form
                                                            action={`/catalogues/${item.id}`}
                                                            method="delete"
                                                            className="inline"
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
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
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        Delete
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </Form>
                                                    </div>
                                                </td>
                                            ) : null}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>

                <AppIndexListCard
                    title="Payment modes (read-only)"
                    className="mt-4"
                >
                    <ul className="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        {paymentModes.map((mode) => (
                            <li
                                key={mode.id}
                                className="rounded-lg border border-[#e5e7eb] p-3 text-sm"
                            >
                                <p className="font-medium">{mode.name}</p>
                                <p className="text-slate-500">
                                    {mode.code} ·{' '}
                                    {mode.is_active ? 'Active' : 'Inactive'}
                                </p>
                            </li>
                        ))}
                    </ul>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
