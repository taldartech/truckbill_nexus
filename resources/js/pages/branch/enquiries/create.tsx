import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    parties: { id: string; name: string }[];
    customers?: { id: string; name: string }[];
    vehicle_types: { id: string; name: string }[];
    urls: {
        index: string;
        store: string;
        parties_create?: string;
    };
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

export default function BranchEnquiryCreate({
    company,
    branch,
    parties,
    customers,
    vehicle_types,
    urls,
}: Props) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;

    const customerOptions = customers ?? parties;

    return (
        <>
            <Head title={`Create enquiry · ${branch.name}`} />
            <PageShell
                width="form"
                title="Create enquiry"
                description={`${company.name} · ${branch.name} — structured broker enquiry capture.`}
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back to enquiries"
                        href={urls.index}
                        variant="outline"
                    />
                }
            >
                <Form
                    action={urls.store}
                    method="post"
                    className="w-full space-y-5"
                >
                    {({ processing }) => (
                        <>
                            <FormSection
                                title="Customer and enquiry"
                                columns={2}
                            >
                                <div className="md:col-span-2 lg:col-span-1">
                                    <SearchablePartySelect
                                        id="customer_party_id"
                                        name="customer_party_id"
                                        label="Customer"
                                        options={customerOptions}
                                        required
                                        emptyMessage="No eligible Customers in this branch. Create a Customer party first."
                                        createHref={urls.parties_create}
                                        createLabel="Create Customer"
                                        error={errors?.customer_party_id}
                                    />
                                </div>
                            </FormSection>

                            <FormSection title="Route" columns={2}>
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
                            </FormSection>

                            <FormSection title="Vehicle" columns={3}>
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
                            </FormSection>

                            <FormSection title="Goods" columns={3}>
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
                            </FormSection>

                            <FormSection title="Instructions" columns={1}>
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
                            </FormSection>

                            <div className="sticky bottom-0 z-10 flex flex-wrap gap-3 border-t border-border bg-background/95 py-4 backdrop-blur">
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                    className="gap-2"
                                >
                                    <Save className="size-4" />
                                    {processing ? 'Saving…' : 'Save enquiry'}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={urls.index}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PageShell>
        </>
    );
}
