import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import {
    LrfPage,
    LrfSection,
} from '@/pages/branch/lorry-receipts/lr-form-sections';
import { SearchablePartySelect } from '@/components/searchable-party-select';
import {
    SearchableVehicleSelect,
    type VehicleSelectOption,
} from '@/components/searchable-vehicle-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EligibleReceipt = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    vehicle_id: string | null;
    vehicle_registration: string | null;
    billing_customer_name: string | null;
    freight_total: string | null;
};

type Props = {
    filters: { vehicle_id: string | null };
    vehicles: VehicleSelectOption[];
    payees: Array<{ id: string; name: string }>;
    eligibleReceipts: EligibleReceipt[];
    permissions: { can_create: boolean };
    urls: {
        index: string;
        store: string;
        filter: string;
        parties_options?: string;
        vehicles_options?: string;
        vehicles_create?: string;
        vehicles_store?: string;
        parties_store?: string;
    };
};

export default function LinkedBiltiesCreate({
    filters,
    vehicles,
    payees,
    eligibleReceipts,
    urls,
}: Props) {
    const [vehicleId, setVehicleId] = useState(filters.vehicle_id ?? '');
    const [selected, setSelected] = useState<string[]>([]);
    const submitAllowed = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const form = useForm({
        group_date: '',
        vehicle_id: vehicleId,
        payee_party_id: '',
        driver_name: '',
        reference: '',
        remarks: '',
        lorry_receipt_ids: [] as string[],
    });

    const visibleReceipts = useMemo(() => {
        if (!vehicleId) {
            return eligibleReceipts;
        }

        return eligibleReceipts.filter((lr) => lr.vehicle_id === vehicleId);
    }, [eligibleReceipts, vehicleId]);

    const toggleReceipt = (id: string) => {
        setSelected((current) => {
            const next = current.includes(id)
                ? current.filter((row) => row !== id)
                : [...current, id];
            form.setData('lorry_receipt_ids', next);
            return next;
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!submitAllowed.current) {
            const vehicleLabel =
                vehicles.find((row) => row.id === vehicleId)
                    ?.registration_number ?? '—';
            const payeeLabel =
                payees.find((row) => row.id === form.data.payee_party_id)
                    ?.name ?? '—';
            setReviewFields([
                {
                    label: 'Group date',
                    value: form.data.group_date || '—',
                },
                { label: 'Vehicle', value: vehicleLabel },
                { label: 'Payee', value: payeeLabel },
                { label: 'Bilties selected', value: String(selected.length) },
                {
                    label: 'Driver',
                    value: form.data.driver_name || '—',
                },
                {
                    label: 'Reference',
                    value: form.data.reference || '—',
                },
                {
                    label: 'Remarks',
                    value: form.data.remarks || '—',
                },
            ]);
            setConfirmOpen(true);
            return;
        }
        submitAllowed.current = false;
        form.transform((data) => ({
            ...data,
            vehicle_id: vehicleId,
            lorry_receipt_ids: selected,
            payee_party_id: data.payee_party_id || undefined,
        }));
        form.post(urls.store);
    };

    return (
        <>
            <Head title="Create Linked Bilty Group" />
            <LrfPage
                title="Create Linked Bilty Group"
                subtitle="Group market-vehicle bilties that share one payee and payment journey."
                backHref={urls.index}
                backLabel="Linked Bilties"
            >
                <form
                    id="linked-bilty-create-form"
                    className="space-y-3"
                    onSubmit={submit}
                >
                    <LrfSection
                        step={1}
                        title="Document"
                        description="Group date and reference."
                        tone="receipt"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="group_date">Group date</Label>
                            <Input
                                id="group_date"
                                name="group_date"
                                type="date"
                                value={form.data.group_date}
                                onChange={(event) =>
                                    form.setData(
                                        'group_date',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.group_date && (
                                <p className="text-xs text-destructive">
                                    {form.errors.group_date}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="reference">Reference</Label>
                            <Input
                                id="reference"
                                name="reference"
                                value={form.data.reference}
                                onChange={(event) =>
                                    form.setData(
                                        'reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="remarks">Remarks</Label>
                            <Input
                                id="remarks"
                                name="remarks"
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                            />
                        </div>
                    </LrfSection>

                    <LrfSection
                        step={2}
                        title="Vehicle & Payee"
                        description="Market vehicle and intended payee."
                        tone="route"
                    >
                        <SearchableVehicleSelect
                            id="vehicle_id"
                            name="vehicle_id"
                            label="Market vehicle"
                            options={vehicles}
                            searchUrl={urls.vehicles_options}
                            ownership="market"
                            required
                            value={vehicleId}
                            onValueChange={(next) => {
                                setVehicleId(next);
                                form.setData('vehicle_id', next);
                                setSelected([]);
                                router.get(
                                    urls.filter,
                                    { vehicle_id: next || undefined },
                                    {
                                        preserveState: true,
                                        replace: true,
                                    },
                                );
                            }}
                            createStoreUrl={urls.vehicles_store}
                            createHref={urls.vehicles_create}
                            createLabel="Add"
                            partiesOptionsUrl={urls.parties_options}
                            partiesStoreUrl={urls.parties_store}
                            emptyMessage="No market vehicles in this branch."
                            error={form.errors.vehicle_id}
                        />
                        <SearchablePartySelect
                            id="payee_party_id"
                            name="payee_party_id"
                            label="Payee"
                            options={payees}
                            searchUrl={urls.parties_options}
                            role="transporter"
                            roles={['transporter', 'driver']}
                            createStoreUrl={urls.parties_store}
                            createLabel="Add"
                            createRole="transporter"
                            value={form.data.payee_party_id}
                            onValueChange={(value) =>
                                form.setData('payee_party_id', value)
                            }
                            emptyMessage="No eligible payees in this branch."
                        />
                        <div className="space-y-1">
                            <Label htmlFor="driver_name">Driver name</Label>
                            <Input
                                id="driver_name"
                                name="driver_name"
                                value={form.data.driver_name}
                                onChange={(event) =>
                                    form.setData(
                                        'driver_name',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </LrfSection>

                    <LrfSection
                        step={3}
                        title="Member bilties"
                        description="Select at least two eligible Broker bilties."
                        tone="goods"
                        headRight={
                            <span className="text-xs text-slate-500">
                                {selected.length} selected
                            </span>
                        }
                    >
                        {form.errors.lorry_receipt_ids && (
                            <p className="text-xs text-destructive">
                                {form.errors.lorry_receipt_ids}
                            </p>
                        )}
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-sm">
                                <thead className="border-b text-left text-muted-foreground">
                                    <tr>
                                        <th className="w-10 px-2 py-2" />
                                        <th className="px-2 py-2">Bilty No</th>
                                        <th className="px-2 py-2">Date</th>
                                        <th className="px-2 py-2">Vehicle</th>
                                        <th className="px-2 py-2">
                                            Billing customer
                                        </th>
                                        <th className="px-2 py-2">Freight</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visibleReceipts.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-2 py-6 text-center text-muted-foreground"
                                            >
                                                {vehicleId
                                                    ? 'No eligible Broker LRs for this vehicle.'
                                                    : 'Select a vehicle to narrow eligible LRs.'}
                                            </td>
                                        </tr>
                                    ) : (
                                        visibleReceipts.map((lr) => (
                                            <tr
                                                key={lr.id}
                                                className="border-b border-border/50"
                                            >
                                                <td className="px-2 py-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={selected.includes(
                                                            lr.id,
                                                        )}
                                                        onChange={() =>
                                                            toggleReceipt(lr.id)
                                                        }
                                                    />
                                                </td>
                                                <td className="px-2 py-2 font-medium">
                                                    {lr.lr_number}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {lr.lr_date ?? '—'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {lr.vehicle_registration ??
                                                        '—'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {lr.billing_customer_name ??
                                                        '—'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {lr.freight_total ?? '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-4">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || selected.length < 2
                                }
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Create linked bilty group
                            </Button>
                        </div>
                    </LrfSection>
                </form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    mode="create"
                    title="Create this Linked Bilty group?"
                    description="Review vehicle, payee, and selected Bilties before submitting."
                    fields={reviewFields}
                    onConfirm={() => {
                        submitAllowed.current = true;
                        setConfirmOpen(false);
                        window.setTimeout(() => {
                            const formElement = document.getElementById(
                                'linked-bilty-create-form',
                            ) as HTMLFormElement | null;
                            formElement?.requestSubmit();
                        }, 0);
                    }}
                />
            </LrfPage>
        </>
    );
}
