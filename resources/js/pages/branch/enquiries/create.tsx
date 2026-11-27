import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { type ReactNode, useMemo, useRef, useState } from 'react';
import {
    AppFormCard,
    AppFormGrid,
    AppFormPage,
    AppFormSection,
} from '@/components/app-form-page';
import { StateCityFields } from '@/components/geography/state-city-fields';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReviewSection } from '@/components/form-confirmation/types';
import { QuickCreateCatalogueSheet } from '@/components/masters/quick-create-catalogue-sheet';
import {
    SearchablePartySelect,
    type PartyOption,
} from '@/components/searchable-party-select';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { AppIconAction } from '@/components/app-icon-action';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMtLabel } from '@/lib/operational-weight';
import { readCanQuickCreateGeography } from '@/lib/geography-permissions';

type Props = {
    company: { id: string; name: string };
    branch: { id: string; name: string };
    customers: PartyOption[];
    consignors: PartyOption[];
    consignees: PartyOption[];
    goods_types: { id: string; name: string }[];
    vehicle_types: { id: string; name: string }[];
    urls: {
        index: string;
        store: string;
        parties_create?: string;
        parties_store?: string;
        parties_options?: string;
        goods_types_store?: string;
        truck_types_store?: string;
    };
};

export default function BranchEnquiryCreate({
    company,
    branch,
    customers,
    consignors,
    consignees,
    goods_types,
    vehicle_types,
    urls,
}: Props) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const canQuickCreateGeo = readCanQuickCreateGeography(usePage().props);

    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewSections, setReviewSections] = useState<FieldReviewSection[]>(
        [],
    );

    const [customerPartyId, setCustomerPartyId] = useState('');
    const [consignorParty, setConsignorParty] = useState<PartyOption | null>(
        null,
    );
    const [consigneeParty, setConsigneeParty] = useState<PartyOption | null>(
        null,
    );
    const [goodsTypeId, setGoodsTypeId] = useState('');
    const [extraGoodsTypes, setExtraGoodsTypes] = useState<ComboboxOption[]>(
        [],
    );
    const [extraVehicleTypes, setExtraVehicleTypes] = useState<
        ComboboxOption[]
    >([]);
    const [goodsQuickCreateOpen, setGoodsQuickCreateOpen] = useState(false);
    const [vehicleQuickCreateOpen, setVehicleQuickCreateOpen] = useState(false);

    const [fromRoute, setFromRoute] = useState<RouteSelection>(EMPTY_ROUTE);
    const [toRoute, setToRoute] = useState<RouteSelection>(EMPTY_ROUTE);

    const goodsComboboxOptions = useMemo(
        () => [
            ...goods_types.map((row) => ({ id: row.id, label: row.name })),
            ...extraGoodsTypes,
        ],
        [extraGoodsTypes, goods_types],
    );

    const vehicleComboboxOptions = useMemo(
        () => [
            ...vehicle_types.map((row) => ({ id: row.id, label: row.name })),
            ...extraVehicleTypes,
        ],
        [extraVehicleTypes, vehicle_types],
    );

    const originLocation =
        errors?.from_state_id || errors?.from_city_id
            ? null
            : partyMasterLocation(consignorParty);
    const destinationLocation =
        errors?.to_state_id || errors?.to_city_id
            ? null
            : partyMasterLocation(consigneeParty);

    const selectConsignor = (party: PartyOption | null) => {
        setConsignorParty(party);
        setFromRoute(EMPTY_ROUTE);
    };

    const selectConsignee = (party: PartyOption | null) => {
        setConsigneeParty(party);
        setToRoute(EMPTY_ROUTE);
    };

    const labelForGoods = (id: string) =>
        goodsComboboxOptions.find((row) => row.id === id)?.label ?? '—';

    const labelForVehicle = (id: string) =>
        vehicleComboboxOptions.find((row) => row.id === id)?.label ?? '—';

    const labelForParty = (id: string, options: PartyOption[]) =>
        options.find((row) => row.id === id)?.short_name ||
        options.find((row) => row.id === id)?.name ||
        '—';

    const routeReviewValue = (location: string | null, route: RouteSelection) =>
        location ??
        ([route.city, route.state].filter((part) => part !== '').join(', ') ||
            '—');

    const openConfirmation = (form: HTMLFormElement) => {
        const data = new FormData(form);

        setReviewSections([
            {
                title: 'Enquiry information',
                fields: [
                    {
                        label: 'Goods type',
                        value: labelForGoods(
                            (data.get('goods_type_id') as string) ?? '',
                        ),
                    },
                    {
                        label: 'Weight (MT)',
                        value: formatMtLabel(
                            (data.get('weight_mt') as string) ?? '',
                        ),
                    },
                    {
                        label: 'Vehicle type',
                        value: labelForVehicle(
                            (data.get('vehicle_type_id') as string) ?? '',
                        ),
                    },
                    {
                        label: 'Pickup date',
                        value:
                            ((data.get('expected_loading_date') as string) ??
                                '') ||
                            '—',
                    },
                ],
            },
            {
                title: 'Party and route',
                fields: [
                    {
                        label: 'Party name',
                        value:
                            ((data.get('party_name') as string) ?? '').trim() ||
                            '—',
                    },
                    {
                        label: 'Customer',
                        value: customerPartyId
                            ? labelForParty(customerPartyId, customers)
                            : '—',
                    },
                    {
                        label: 'Consignor',
                        value:
                            consignorParty?.short_name ||
                            consignorParty?.name ||
                            '—',
                    },
                    {
                        label: 'Consignee',
                        value:
                            consigneeParty?.short_name ||
                            consigneeParty?.name ||
                            '—',
                    },
                    {
                        label: 'From',
                        value: routeReviewValue(originLocation, fromRoute),
                    },
                    {
                        label: 'To',
                        value: routeReviewValue(destinationLocation, toRoute),
                    },
                ],
            },
            {
                title: 'Notes',
                fields: [
                    {
                        label: 'Notes',
                        value:
                            (
                                (data.get('instructions') as string) ?? ''
                            ).trim() || '—',
                    },
                ],
            },
        ]);
        setConfirmOpen(true);
    };

    const handleBeforeSubmit = (): boolean => {
        if (!submitAllowed.current) {
            const form = document.getElementById('enquiry-create-form');
            if (form instanceof HTMLFormElement) {
                openConfirmation(form);
            }
            return false;
        }
        submitAllowed.current = false;
        return true;
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
            <AppFormPage
                title="Create enquiry"
                description={`${company.name} · ${branch.name}`}
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
                    id="enquiry-create-form"
                    action={urls.store}
                    method="post"
                    className="space-y-6"
                    onBefore={handleBeforeSubmit}
                >
                    {({ processing }) => (
                        <>
                            <AppFormCard>
                                <AppFormSection
                                    title="Enquiry information"
                                    description="Goods, weight, vehicle, and pickup date."
                                >
                                    <AppFormGrid cols={3}>
                                        <div className="space-y-2 md:col-span-2">
                                            <SearchableCombobox
                                                id="goods_type_id"
                                                name="goods_type_id"
                                                label="Goods type"
                                                options={goodsComboboxOptions}
                                                value={goodsTypeId}
                                                required
                                                error={errors?.goods_type_id}
                                                addAction={
                                                    urls.goods_types_store
                                                        ? {
                                                              label: '+ Add',
                                                              onClick: () =>
                                                                  setGoodsQuickCreateOpen(
                                                                      true,
                                                                  ),
                                                          }
                                                        : undefined
                                                }
                                                onValueChange={(id) =>
                                                    setGoodsTypeId(id)
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="weight_mt">
                                                Weight (MT)
                                            </Label>
                                            <Input
                                                id="weight_mt"
                                                name="weight_mt"
                                                type="number"
                                                min={0}
                                                step="0.001"
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <SearchableCombobox
                                                id="vehicle_type_id"
                                                name="vehicle_type_id"
                                                label="Vehicle type"
                                                options={vehicleComboboxOptions}
                                                required
                                                error={errors?.vehicle_type_id}
                                                addAction={
                                                    urls.truck_types_store
                                                        ? {
                                                              label: '+ Add',
                                                              onClick: () =>
                                                                  setVehicleQuickCreateOpen(
                                                                      true,
                                                                  ),
                                                          }
                                                        : undefined
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="expected_loading_date">
                                                Pickup date
                                            </Label>
                                            <Input
                                                id="expected_loading_date"
                                                name="expected_loading_date"
                                                type="date"
                                            />
                                        </div>
                                    </AppFormGrid>
                                </AppFormSection>
                            </AppFormCard>

                            <AppFormCard>
                                <AppFormSection
                                    title="Party and route information"
                                    description="Party, consignor/consignee, and from/to geography."
                                >
                                    <AppFormGrid cols={2}>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label htmlFor="party_name">
                                                Party name
                                            </Label>
                                            <Input
                                                id="party_name"
                                                name="party_name"
                                                placeholder="Free-text party when not selecting a customer master"
                                            />
                                        </div>
                                        <SearchablePartySelect
                                            id="customer_party_id"
                                            name="customer_party_id"
                                            label="Customer (optional)"
                                            options={customers}
                                            searchUrl={urls.parties_options}
                                            role="customer"
                                            value={customerPartyId}
                                            onValueChange={setCustomerPartyId}
                                            createStoreUrl={urls.parties_store}
                                            createHref={urls.parties_create}
                                            createLabel="Add"
                                            createRole="customer"
                                            error={errors?.customer_party_id}
                                        />
                                        <SearchablePartySelect
                                            id="consignor_party_id"
                                            name="consignor_party_id"
                                            label="Consignor (optional)"
                                            options={consignors}
                                            searchUrl={urls.parties_options}
                                            role="consignor"
                                            value={consignorParty?.id ?? ''}
                                            onPartyChange={selectConsignor}
                                            createStoreUrl={urls.parties_store}
                                            createHref={urls.parties_create}
                                            createLabel="Add"
                                            createRole="consignor"
                                            error={errors?.consignor_party_id}
                                        />
                                        <SearchablePartySelect
                                            id="consignee_party_id"
                                            name="consignee_party_id"
                                            label="Consignee (optional)"
                                            options={consignees}
                                            searchUrl={urls.parties_options}
                                            role="consignee"
                                            value={consigneeParty?.id ?? ''}
                                            onPartyChange={selectConsignee}
                                            createStoreUrl={urls.parties_store}
                                            createHref={urls.parties_create}
                                            createLabel="Add"
                                            createRole="consignee"
                                            error={errors?.consignee_party_id}
                                        />
                                    </AppFormGrid>

                                    <div className="mt-4 space-y-4">
                                        <RouteEndpoint
                                            title="From"
                                            source="consignor"
                                            location={originLocation}
                                        >
                                            <StateCityFields
                                                fieldPrefix="from_"
                                                valueMode="ids"
                                                required
                                                stateId={fromRoute.state_id}
                                                cityId={fromRoute.city_id}
                                                stateName={fromRoute.state}
                                                cityName={fromRoute.city}
                                                canQuickCreate={
                                                    canQuickCreateGeo
                                                }
                                                stateError={
                                                    errors?.from_state_id
                                                }
                                                cityError={errors?.from_city_id}
                                                onChange={setFromRoute}
                                            />
                                        </RouteEndpoint>
                                        <RouteEndpoint
                                            title="To"
                                            source="consignee"
                                            location={destinationLocation}
                                        >
                                            <StateCityFields
                                                fieldPrefix="to_"
                                                valueMode="ids"
                                                required
                                                stateId={toRoute.state_id}
                                                cityId={toRoute.city_id}
                                                stateName={toRoute.state}
                                                cityName={toRoute.city}
                                                canQuickCreate={
                                                    canQuickCreateGeo
                                                }
                                                stateError={errors?.to_state_id}
                                                cityError={errors?.to_city_id}
                                                onChange={setToRoute}
                                            />
                                        </RouteEndpoint>
                                    </div>
                                </AppFormSection>
                            </AppFormCard>

                            <AppFormCard>
                                <AppFormSection title="Notes">
                                    <div className="space-y-2">
                                        <Label htmlFor="instructions">
                                            Notes
                                        </Label>
                                        <textarea
                                            id="instructions"
                                            name="instructions"
                                            rows={4}
                                            className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                </AppFormSection>
                            </AppFormCard>

                            <div className="flex flex-wrap gap-3">
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
                    description="Review enquiry, party, and route details before submitting."
                    sections={reviewSections}
                    onConfirm={handleConfirm}
                />
            </AppFormPage>

            {urls.goods_types_store ? (
                <QuickCreateCatalogueSheet
                    open={goodsQuickCreateOpen}
                    onOpenChange={setGoodsQuickCreateOpen}
                    title="Quick add goods type"
                    storeUrl={urls.goods_types_store}
                    onCreated={(item) => {
                        const option = { id: item.id, label: item.name };
                        setExtraGoodsTypes((current) => [...current, option]);
                        setGoodsTypeId(item.id);
                    }}
                />
            ) : null}

            {urls.truck_types_store ? (
                <QuickCreateCatalogueSheet
                    open={vehicleQuickCreateOpen}
                    onOpenChange={setVehicleQuickCreateOpen}
                    title="Quick add vehicle type"
                    storeUrl={urls.truck_types_store}
                    onCreated={(item) => {
                        const option = { id: item.id, label: item.name };
                        setExtraVehicleTypes((current) => [...current, option]);
                    }}
                />
            ) : null}
        </>
    );
}

type RouteSelection = {
    state_id: string;
    city_id: string;
    state: string;
    city: string;
};

const EMPTY_ROUTE: RouteSelection = {
    state_id: '',
    city_id: '',
    state: '',
    city: '',
};

/** Display label for a party's master location, or null when the route must be entered. */
function partyMasterLocation(party: PartyOption | null): string | null {
    if (!party?.state_id || !party.city_id || !party.city || !party.state) {
        return null;
    }

    return `${party.city}, ${party.state}`;
}

function RouteEndpoint({
    title,
    source,
    location,
    children,
}: {
    title: string;
    source: 'consignor' | 'consignee';
    location: string | null;
    children: ReactNode;
}) {
    return (
        <div className="space-y-2" data-testid={`enquiry-route-${source}`}>
            <p className="text-sm font-medium text-foreground">{title}</p>
            {location ? (
                <p className="text-sm text-muted-foreground">
                    <span className="text-foreground">{location}</span> · from{' '}
                    {source}
                </p>
            ) : (
                children
            )}
        </div>
    );
}
