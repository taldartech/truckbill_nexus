import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { PageShell } from '@/components/page-shell';
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
    vehicles: Array<{ id: string; registration_number: string }>;
    payees: Array<{ id: string; name: string }>;
    eligibleReceipts: EligibleReceipt[];
    permissions: { can_create: boolean };
    urls: { index: string; store: string; filter: string };
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
            <PageShell
                title="Create Linked Bilty Group"
                actions={
                    <Link
                        href={urls.index}
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Back to list
                    </Link>
                }
            >
                <form className="space-y-8" onSubmit={submit}>
                    <section className="grid gap-4 rounded-lg border border-border p-4 md:grid-cols-2">
                        <h2 className="text-sm font-semibold text-[#2FB36F] md:col-span-2">
                            Shared header
                        </h2>
                        <div className="space-y-1">
                            <Label htmlFor="group_date">Group date</Label>
                            <Input
                                id="group_date"
                                name="group_date"
                                type="date"
                            />
                            {form.errors.group_date && (
                                <p className="text-xs text-destructive">
                                    {form.errors.group_date}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="vehicle_id">Vehicle</Label>
                            <select
                                id="vehicle_id"
                                name="vehicle_id"
                                required
                                value={vehicleId}
                                onChange={(event) => {
                                    const next = event.target.value;
                                    setVehicleId(next);
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
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">Select vehicle</option>
                                {vehicles.map((vehicle) => (
                                    <option key={vehicle.id} value={vehicle.id}>
                                        {vehicle.registration_number}
                                    </option>
                                ))}
                            </select>
                            {form.errors.vehicle_id && (
                                <p className="text-xs text-destructive">
                                    {form.errors.vehicle_id}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="payee_party_id">Payee</Label>
                            <select
                                id="payee_party_id"
                                name="payee_party_id"
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">Optional</option>
                                {payees.map((payee) => (
                                    <option key={payee.id} value={payee.id}>
                                        {payee.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="driver_name">Driver name</Label>
                            <Input id="driver_name" name="driver_name" />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="reference">Reference</Label>
                            <Input id="reference" name="reference" />
                        </div>
                        <div className="space-y-1 md:col-span-2">
                            <Label htmlFor="remarks">Remarks</Label>
                            <Input id="remarks" name="remarks" />
                        </div>
                    </section>

                    <section className="space-y-3 rounded-lg border border-border p-4">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold text-[#2FB36F]">
                                Member bilties (minimum 2)
                            </h2>
                            <span className="text-xs text-muted-foreground">
                                {selected.length} selected
                            </span>
                        </div>
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
                                        <th className="px-2 py-2">LR No</th>
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
                    </section>

                    <Button
                        type="submit"
                        disabled={form.processing || selected.length < 2}
                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                    >
                        Create linked bilty group
                    </Button>
                </form>
            </PageShell>
        </>
    );
}
