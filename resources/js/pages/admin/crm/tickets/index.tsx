import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    filters: { status: string };
    statuses: string[];
    urls: { index: string; create: string };
    tickets: {
        data: {
            id: string;
            subject: string;
            category: string;
            priority: string;
            status: string;
            assignee: string | null;
            due_at: string | null;
            is_overdue: boolean;
            show_url: string;
        }[];
        meta: { current_page: number; last_page: number; total: number };
    };
};

export default function CrmTicketsIndex({
    filters,
    statuses,
    urls,
    tickets,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    return (
        <>
            <Head title="Support tickets" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Internal · CRM
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Support tickets
                        </h1>
                    </div>
                    <Button asChild>
                        <Link href={urls.create}>Create ticket</Link>
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
                                    Subject
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Priority
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium">Due</th>
                                <th className="px-4 py-3 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {tickets.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No tickets found.
                                    </td>
                                </tr>
                            ) : (
                                tickets.data.map((ticket) => (
                                    <tr
                                        key={ticket.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {ticket.subject}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {ticket.category}
                                                {ticket.assignee
                                                    ? ` · ${ticket.assignee}`
                                                    : ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {ticket.priority}
                                        </td>
                                        <td className="px-4 py-3">
                                            {ticket.status}
                                        </td>
                                        <td className="px-4 py-3">
                                            {ticket.is_overdue
                                                ? 'Overdue'
                                                : (ticket.due_at ?? '—')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={ticket.show_url}
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
            </div>
        </>
    );
}
