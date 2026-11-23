import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type AssignableUser = { id: number; name: string; email: string };

type Props = {
    sources: string[];
    products: string[];
    assignable_users?: AssignableUser[];
    can_assign?: boolean;
    store_url: string;
    index_url: string;
};

export default function CrmLeadCreate({
    sources,
    products,
    assignable_users = [],
    can_assign = false,
    store_url,
    index_url,
}: Props) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title="Create lead" />
            <div className="mx-auto flex max-w-3xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={index_url}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Leads
                    </Link>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Create lead
                    </h1>
                </div>

                {errors?.email ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.email}
                    </p>
                ) : null}

                <Form action={store_url} method="post" className="grid gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        Contact name
                        <input
                            name="contact_name"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Email
                        <input
                            name="email"
                            type="email"
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Mobile
                        <input
                            name="mobile"
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Company name
                        <input
                            name="company_name"
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Source
                        <select
                            name="source"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                            defaultValue={sources[0]}
                        >
                            {sources.map((source) => (
                                <option key={source} value={source}>
                                    {source}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Interested product
                        <select
                            name="interested_product"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                            defaultValue={products[0]}
                        >
                            {products.map((product) => (
                                <option key={product} value={product}>
                                    {product}
                                </option>
                            ))}
                        </select>
                    </label>
                    {can_assign ? (
                        <label className="flex flex-col gap-1 text-sm">
                            Assign to
                            <select
                                name="assigned_to_user_id"
                                className="rounded-md border border-border bg-background px-3 py-2"
                                defaultValue=""
                            >
                                <option value="">Unassigned</option>
                                {assignable_users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name} ({user.email})
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : null}
                    <label className="flex flex-col gap-1 text-sm">
                        Remarks
                        <textarea
                            name="remarks"
                            rows={3}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="duplicate_override"
                            value="1"
                        />
                        Override potential duplicate warning
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Duplicate override remark
                        <textarea
                            name="duplicate_override_remark"
                            rows={2}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <Button type="submit">Save lead</Button>
                </Form>
            </div>
        </>
    );
}
