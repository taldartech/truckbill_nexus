import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    parties: { id: string; name: string }[];
    vehicle_types: { id: string; name: string }[];
    urls: { index: string; store: string };
};

export default function BranchEnquiryCreate({
    company,
    branch,
    parties,
    vehicle_types,
    urls,
}: Props) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;

    return (
        <>
            <Head title={`Create enquiry · ${branch.name}`} />
            <div className="mx-auto flex max-w-3xl flex-col gap-6 p-4 md:p-8">
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
                        Create enquiry
                    </h1>
                </div>

                {errors?.customer_party_id ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.customer_party_id}
                    </p>
                ) : null}

                <Form action={urls.store} method="post" className="grid gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        Customer party
                        <select
                            name="customer_party_id"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                            defaultValue=""
                        >
                            <option value="" disabled>
                                Select party
                            </option>
                            {parties.map((party) => (
                                <option key={party.id} value={party.id}>
                                    {party.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Pickup location
                        <input
                            name="pickup_location"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Delivery location
                        <input
                            name="delivery_location"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Vehicle type
                        <select
                            name="vehicle_type_id"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                            defaultValue=""
                        >
                            <option value="" disabled>
                                Select vehicle type
                            </option>
                            {vehicle_types.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Vehicle quantity
                        <input
                            name="vehicle_quantity"
                            type="number"
                            min={1}
                            defaultValue={1}
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Goods description
                        <input
                            name="goods[0][goods_description]"
                            required
                            className="rounded-md border border-border bg-background px-3 py-2"
                            placeholder="General cargo"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Package count
                        <input
                            name="goods[0][package_count]"
                            type="number"
                            min={0}
                            defaultValue={1}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Instructions
                        <textarea
                            name="instructions"
                            rows={3}
                            className="rounded-md border border-border bg-background px-3 py-2"
                        />
                    </label>
                    <Button type="submit">Save enquiry</Button>
                </Form>
            </div>
        </>
    );
}
