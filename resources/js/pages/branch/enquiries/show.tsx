import { Head, Link, usePage } from '@inertiajs/react';

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    enquiry: {
        id: string;
        enquiry_number: string | null;
        status: string;
        source: string;
        pickup_location: string;
        delivery_location: string;
        vehicle_quantity: number;
        instructions: string | null;
        goods: {
            id: string;
            position: number;
            goods_description: string | null;
            package_count: string | number | null;
            actual_weight: string | number | null;
            charged_weight: string | number | null;
        }[];
        status_history: {
            from_status: string | null;
            to_status: string;
            remark: string | null;
            changed_at: string | null;
        }[];
    };
    urls: { index: string };
};

export default function BranchEnquiryShow({
    company,
    branch,
    enquiry,
    urls,
}: Props) {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    return (
        <>
            <Head
                title={`${enquiry.enquiry_number ?? 'Enquiry'} · ${branch.name}`}
            />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← Enquiries
                    </Link>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {company.name} · {branch.name}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {enquiry.enquiry_number ?? enquiry.id.slice(0, 8)}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {enquiry.status} · {enquiry.source}
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

                <section className="space-y-2 rounded-xl border border-border p-4 text-sm">
                    <p>
                        {enquiry.pickup_location} → {enquiry.delivery_location}
                    </p>
                    <p>Vehicles requested: {enquiry.vehicle_quantity}</p>
                    {enquiry.instructions ? (
                        <p>Instructions: {enquiry.instructions}</p>
                    ) : null}
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Goods</h2>
                    {enquiry.goods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No goods rows.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {enquiry.goods.map((row) => (
                                <li
                                    key={row.id}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    #{row.position}{' '}
                                    {row.goods_description ?? 'Goods'}
                                    <div className="text-xs text-muted-foreground">
                                        Packages: {row.package_count ?? '—'} ·
                                        Actual: {row.actual_weight ?? '—'} ·
                                        Charged: {row.charged_weight ?? '—'}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Status history</h2>
                    {enquiry.status_history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No history.
                        </p>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {enquiry.status_history.map((row, index) => (
                                <li
                                    key={`${row.changed_at}-${index}`}
                                    className="rounded-md border border-border px-3 py-2"
                                >
                                    {row.from_status ?? '—'} → {row.to_status}
                                    {row.remark ? ` — ${row.remark}` : ''}
                                    <div className="text-xs text-muted-foreground">
                                        {row.changed_at}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
