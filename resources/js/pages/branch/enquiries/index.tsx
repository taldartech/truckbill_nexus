import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type EnquiryRow = {
    id: string;
    enquiry_number: string | null;
    status: string;
    source: string;
    pickup_location: string;
    delivery_location: string;
    vehicle_quantity: number;
};

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    filters: { unassigned: boolean };
    can_create: boolean;
    can_include_unassigned: boolean;
    enquiries: {
        data: EnquiryRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            includes_unassigned: boolean;
        };
    };
    urls: { index: string; create: string; masters: string };
};

export default function BranchEnquiriesIndex({
    company,
    branch,
    filters,
    can_create,
    can_include_unassigned,
    enquiries,
    urls,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    return (
        <>
            <Head title={`Enquiries · ${branch.name}`} />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Branch · {company.name} · {branch.name}
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Enquiries
                        </h1>
                    </div>
                    {can_create ? (
                        <Button asChild>
                            <Link href={urls.create}>Create enquiry</Link>
                        </Button>
                    ) : null}
                </div>

                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}

                {can_include_unassigned ? (
                    <Form
                        action={urls.index}
                        method="get"
                        className="flex items-center gap-3 rounded-xl border border-border p-4 text-sm"
                    >
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                name="unassigned"
                                value="1"
                                defaultChecked={filters.unassigned}
                            />
                            Include company unassigned queue
                        </label>
                        <Button type="submit" size="sm">
                            Apply
                        </Button>
                    </Form>
                ) : null}

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[44rem] text-left text-sm">
                        <thead className="border-b border-border bg-muted/30 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Number
                                </th>
                                <th className="px-4 py-3 font-medium">Route</th>
                                <th className="px-4 py-3 font-medium">
                                    Vehicles
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {enquiries.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No enquiries found.
                                    </td>
                                </tr>
                            ) : (
                                enquiries.data.map((enquiry) => (
                                    <tr
                                        key={enquiry.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {enquiry.enquiry_number ??
                                                enquiry.id.slice(0, 8)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {enquiry.pickup_location} →{' '}
                                            {enquiry.delivery_location}
                                        </td>
                                        <td className="px-4 py-3">
                                            {enquiry.vehicle_quantity}
                                        </td>
                                        <td className="px-4 py-3">
                                            {enquiry.status} · {enquiry.source}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`${urls.index}/${enquiry.id}`}
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
                    Page {enquiries.meta.current_page} of{' '}
                    {enquiries.meta.last_page} ({enquiries.meta.total} total)
                    {enquiries.meta.includes_unassigned
                        ? ' · includes unassigned'
                        : ''}
                </p>
            </div>
        </>
    );
}
