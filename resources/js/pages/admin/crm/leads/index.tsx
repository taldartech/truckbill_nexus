import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    filters: { q: string; status: string };
    statuses: string[];
    urls: { index: string; create: string };
    leads: {
        data: {
            id: string;
            contact_name: string;
            email: string | null;
            company_name: string | null;
            status: string;
            source: string;
            interested_product: string;
            assignee: string | null;
            show_url: string;
        }[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function CrmLeadsIndex({
    filters,
    statuses,
    urls,
    leads,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    return (
        <>
            <Head title="CRM Leads" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Internal · CRM
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Leads
                        </h1>
                    </div>
                    <Button asChild>
                        <Link href={urls.create}>Create lead</Link>
                    </Button>
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
                    action={urls.index}
                    method="get"
                    className="flex flex-col gap-3 rounded-xl border border-border p-4 md:flex-row md:items-end"
                >
                    <label className="flex flex-1 flex-col gap-1 text-sm">
                        Search
                        <input
                            name="q"
                            defaultValue={filters.q}
                            className="rounded-md border border-border bg-background px-3 py-2"
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
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit">Filter</Button>
                </Form>

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[44rem] text-left text-sm">
                        <thead className="border-b border-border bg-muted/30 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Contact
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Product
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Assignee
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {leads.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No leads found.
                                    </td>
                                </tr>
                            ) : (
                                leads.data.map((lead) => (
                                    <tr
                                        key={lead.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {lead.contact_name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {lead.email ?? '—'}
                                                {lead.company_name
                                                    ? ` · ${lead.company_name}`
                                                    : ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {lead.interested_product}
                                        </td>
                                        <td className="px-4 py-3">
                                            {lead.status}
                                        </td>
                                        <td className="px-4 py-3">
                                            {lead.assignee ?? 'Unassigned'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={lead.show_url}
                                                className="font-medium underline-offset-4 hover:underline"
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
                    Page {leads.meta.current_page} of {leads.meta.last_page} (
                    {leads.meta.total} total)
                </p>
            </div>
        </>
    );
}
