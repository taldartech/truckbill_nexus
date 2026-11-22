import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
    return (
        <>
            <Head title={`Supporting masters · ${branch.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    eyebrow="Branch Panel · Supporting Masters"
                    title="Supporting masters"
                    company={company}
                    branch={branch}
                    crumbs={[
                        { label: 'Masters', href: links.hub },
                        { label: 'Supporting masters' },
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
                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[480px] text-left text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 font-medium">Code</th>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                {canManageOwnerCatalogues ? (
                                    <th className="px-4 py-3 font-medium">
                                        Actions
                                    </th>
                                ) : null}
                            </tr>
                        </thead>
                        <tbody>
                            {items.data.map((item) => (
                                <tr
                                    key={item.id}
                                    className="border-t border-border"
                                >
                                    <td className="px-4 py-3 font-mono">
                                        {item.code}
                                    </td>
                                    <td className="px-4 py-3">{item.name}</td>
                                    <td className="px-4 py-3">
                                        {item.is_active ? 'Active' : 'Inactive'}
                                    </td>
                                    {canManageOwnerCatalogues ? (
                                        <td className="px-4 py-3">
                                            <Form
                                                action={`/branch/companies/${company.id}/branches/${branch.id}/catalogues/${item.id}`}
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
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Delete
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
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
