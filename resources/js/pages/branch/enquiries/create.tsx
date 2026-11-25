import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
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
        parties_store?: string;
        parties_options?: string;
    };
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

function formValue(data: FormData, key: string, fallback = ''): string {
    const value = data.get(key);
    return typeof value === 'string' ? value : fallback;
}

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
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const [customerPartyId, setCustomerPartyId] = useState('');

    const openConfirmation = (form: HTMLFormElement) => {
        const data = new FormData(form);
        const customerName =
            customerOptions.find((option) => option.id === customerPartyId)
                ?.name ??
            (
                form.querySelector(
                    '#customer_party_id',
                ) as HTMLSelectElement | null
            )?.selectedOptions?.[0]?.text?.trim() ??
            '—';
        const vehicleTypeId = formValue(data, 'vehicle_type_id');
        const vehicleTypeName =
            vehicle_types.find((type) => type.id === vehicleTypeId)?.name ??
            '—';

        setReviewFields([
            { label: 'Customer', value: customerName },
            {
                label: 'Pickup',
                value: formValue(data, 'pickup_location').trim() || '—',
            },
            {
                label: 'Delivery',
                value: formValue(data, 'delivery_location').trim() || '—',
            },
            { label: 'Vehicle type', value: vehicleTypeName },
            {
                label: 'Vehicle quantity',
                value: formValue(data, 'vehicle_quantity', '1'),
            },
            {
                label: 'Goods',
                value:
                    formValue(data, 'goods[0][goods_description]').trim() ||
                    '—',
            },
        ]);
        setConfirmOpen(true);
    };

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        if (!submitAllowed.current) {
            event.preventDefault();
            openConfirmation(event.currentTarget);
            return;
        }
        submitAllowed.current = false;
    };

    const handleConfirm = () => {
        submitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => {
            const form = document.getElementById(
                'enquiry-create-form',
            ) as HTMLFormElement | null;
            form?.requestSubmit();
        }, 0);
    };

    return (
        <>
            <Head title={`Create enquiry · ${branch.name}`} />
            <LrfPage
                title="Create enquiry"
                subtitle={`${company.name} · ${branch.name} — structured broker enquiry capture.`}
                backHref={urls.index}
                backLabel="Enquiries"
            >
                <Form
                    id="enquiry-create-form"
                    action={urls.store}
                    method="post"
                    className="space-y-3"
                    onSubmit={handleSubmit}
                >
                    {({ processing }) => (
                        <>
                            <LrfSection
                                step={1}
                                title="Customer and enquiry"
                                description="Assign the customer party for this pipeline entry."
                                tone="receipt"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="md:col-span-2 lg:col-span-1">
                                        <SearchablePartySelect
                                            id="customer_party_id"
                                            name="customer_party_id"
                                            label="Customer"
                                            options={customerOptions}
                                            searchUrl={urls.parties_options}
                                            role="customer"
                                            required
                                            value={customerPartyId}
                                            onValueChange={setCustomerPartyId}
                                            emptyMessage="No eligible Customers in this branch. Create a Customer party first."
                                            createStoreUrl={urls.parties_store}
                                            createHref={urls.parties_create}
                                            createLabel="Add"
                                            createRole="customer"
                                            error={errors?.customer_party_id}
                                        />
                                    </div>
                                </div>
                            </LrfSection>

                            <LrfSection
                                step={2}
                                title="Route"
                                description="Pickup and delivery locations."
                                tone="route"
                            >
                                <div className="grid gap-3 md:grid-cols-2">
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
                            </LrfSection>

                            <LrfSection
                                step={3}
                                title="Vehicle"
                                description="Type and quantity requested."
                                tone="goods"
                            >
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    <div className="space-y-2 md:col-span-2">
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
                            </LrfSection>

                            <LrfSection
                                step={4}
                                title="Goods"
                                description="Cargo description and package count."
                                tone="optional"
                            >
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
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
                            </LrfSection>

                            <LrfSection
                                step={5}
                                title="Instructions"
                                description="Operational notes for quoters."
                                tone="optional"
                            >
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
                            </LrfSection>

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

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Create this enquiry?"
                    description="Review customer, route, and vehicle details before submitting."
                    fields={reviewFields}
                    onConfirm={handleConfirm}
                />
            </LrfPage>
        </>
    );
}
