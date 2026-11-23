import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type CompanyRow = {
    id: string;
    name: string;
    pan: string | null;
    is_active: boolean;
    deactivated_at: string | null;
    owner: { id: number; name: string; email: string } | null;
    show_url: string;
};

type Props = {
    filters: { q: string; status: string };
    index_url: string;
    companies: {
        data: CompanyRow[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function AdminCompaniesIndex({
    filters,
    index_url,
    companies,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    return (
        <>
            <Head title="Company lifecycle" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Lifecycle
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Companies
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Deactivate and reactivate companies with mandatory
                        remarks. Ownership transfer is available on company
                        detail.
                    </p>
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                <Form
                    action={index_url}
                    method="get"
                    className="flex flex-col gap-3 rounded-xl border border-border p-4 md:flex-row md:items-end"
                >
                    <label className="flex flex-1 flex-col gap-1 text-sm">
                        Search
                        <input
                            name="q"
                            defaultValue={filters.q}
                            className="rounded-md border border-border bg-background px-3 py-2"
                            placeholder="Name, PAN, or owner"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Status
                        <select
                            name="status"
                            defaultValue={filters.status}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        >
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="deactivated">Deactivated</option>
                        </select>
                    </label>
                    <Button type="submit">Filter</Button>
                </Form>

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[40rem] text-left text-sm">
                        <thead className="border-b border-border bg-muted/30 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Company
                                </th>
                                <th className="px-4 py-3 font-medium">Owner</th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {companies.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No companies match these filters.
                                    </td>
                                </tr>
                            ) : (
                                companies.data.map((company) => (
                                    <tr
                                        key={company.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {company.name}
                                            </div>
                                            {company.pan ? (
                                                <div className="font-mono text-xs text-muted-foreground">
                                                    {company.pan}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="px-4 py-3">
                                            {company.owner ? (
                                                <>
                                                    <div>
                                                        {company.owner.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {company.owner.email}
                                                    </div>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {company.is_active
                                                ? 'Active'
                                                : 'Deactivated'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={company.show_url}
                                                className="text-sm font-medium underline-offset-4 hover:underline"
                                            >
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <p className="text-xs text-muted-foreground">
                    Showing page {companies.meta.current_page} of{' '}
                    {companies.meta.last_page} ({companies.meta.total} total)
                </p>
            </div>
        </>
    );
}
