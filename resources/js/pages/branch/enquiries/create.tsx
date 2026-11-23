import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    parties: { id: string; name: string }[];
    vehicle_types: { id: string; name: string }[];
    urls: { index: string; store: string };
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

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
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
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
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Capture customer, route, vehicle, and goods details in a
                        structured layout for faster daily entry.
                    </p>
                </div>

                {errors?.customer_party_id ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.customer_party_id}
                    </p>
                ) : null}

                <Form
                    action={urls.store}
                    method="post"
                    className="mx-auto w-full max-w-6xl space-y-6"
                >
                    {({ processing }) => (
                        <>
                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Customer and enquiry
                                </h2>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2 md:col-span-2 lg:col-span-1">
                                        <Label htmlFor="customer_party_id">
                                            Customer party
                                        </Label>
                                        <select
                                            id="customer_party_id"
                                            name="customer_party_id"
                                            required
                                            className={fieldClass}
                                            defaultValue=""
                                        >
                                            <option value="" disabled>
                                                Select party
                                            </option>
                                            {parties.map((party) => (
                                                <option
                                                    key={party.id}
                                                    value={party.id}
                                                >
                                                    {party.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Route
                                </h2>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="pickup_location">
                                            Pickup location
                                        </Label>
                                        <Input
                                            id="pickup_location"
                                            name="pickup_location"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="delivery_location">
                                            Delivery location
                                        </Label>
                                        <Input
                                            id="delivery_location"
                                            name="delivery_location"
                                            required
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Vehicle
                                </h2>
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    <div className="space-y-2 md:col-span-2 lg:col-span-2">
                                        <Label htmlFor="vehicle_type_id">
                                            Vehicle type
                                        </Label>
                                        <select
                                            id="vehicle_type_id"
                                            name="vehicle_type_id"
                                            required
                                            className={fieldClass}
                                            defaultValue=""
                                        >
                                            <option value="" disabled>
                                                Select vehicle type
                                            </option>
                                            {vehicle_types.map((type) => (
                                                <option
                                                    key={type.id}
                                                    value={type.id}
                                                >
                                                    {type.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="vehicle_quantity">
                                            Vehicle quantity
                                        </Label>
                                        <Input
                                            id="vehicle_quantity"
                                            name="vehicle_quantity"
                                            type="number"
                                            min={1}
                                            defaultValue={1}
                                            required
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Goods
                                </h2>
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="goods_description">
                                            Goods description
                                        </Label>
                                        <Input
                                            id="goods_description"
                                            name="goods[0][goods_description]"
                                            required
                                            placeholder="General cargo"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="package_count">
                                            Package count
                                        </Label>
                                        <Input
                                            id="package_count"
                                            name="goods[0][package_count]"
                                            type="number"
                                            min={0}
                                            defaultValue={1}
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm md:p-6">
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Instructions
                                </h2>
                                <div className="space-y-2">
                                    <Label htmlFor="instructions">
                                        Special instructions
                                    </Label>
                                    <textarea
                                        id="instructions"
                                        name="instructions"
                                        rows={4}
                                        className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    />
                                </div>
                            </section>

                            <div className="sticky bottom-0 z-10 flex flex-wrap gap-3 border-t border-border bg-background/95 py-4 backdrop-blur">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving…' : 'Save enquiry'}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={urls.index}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
