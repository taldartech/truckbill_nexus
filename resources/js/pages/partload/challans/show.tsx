import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    permissions: {
        can_dispatch: boolean;
        can_cancel: boolean;
        can_create_inward: boolean;
    };
    challan: {
        id: string;
        challan_number: string;
        challan_date: string | null;
        status: string;
        status_label: string;
        ownership_mode: string;
        from_station_name: string | null;
        to_station_name: string | null;
        vehicle_registration: string | null;
        driver_name: string | null;
        truck_freight: string;
        labour_charge: string;
        kat: string;
        home_delivery_charge: string;
        to_pay_amount: string;
        paid_amount: string;
        total_packages: string;
        total_weight_kg: string;
        total_amount: string;
        remark: string | null;
        inward_id: string | null;
        lines: Array<{
            id: string;
            lr_number: string | null;
            billing_customer_name: string | null;
            to_station_name: string | null;
            packages: string;
            weight_kg: string;
            freight_amount: string;
            freight_payment_term: string | null;
        }>;
    };
    urls: {
        index: string;
        dispatch: string | null;
        cancel: string | null;
        create_inward: string | null;
    };
};

export default function PartloadChallanShow({
    permissions,
    challan,
    urls,
}: Props) {
    const cancelForm = useForm({ cancellation_remark: '' });

    return (
        <>
            <Head title={`Challan ${challan.challan_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Challan ${challan.challan_number}`}
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.index}>Register</Link>
                            </Button>
                            {permissions.can_dispatch && urls.dispatch ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                    onClick={() => router.post(urls.dispatch!)}
                                >
                                    Dispatch
                                </Button>
                            ) : null}
                            {permissions.can_create_inward &&
                            urls.create_inward ? (
                                <Form action={urls.create_inward} method="post">
                                    <input
                                        type="hidden"
                                        name="partload_challan_id"
                                        value={challan.id}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="secondary"
                                    >
                                        Create Inward
                                    </Button>
                                </Form>
                            ) : null}
                        </div>
                    }
                >
                    <div className="grid gap-3 p-4 md:grid-cols-3">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Status
                            </div>
                            <div className="font-medium">
                                {challan.status_label}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Route
                            </div>
                            <div className="font-medium">
                                {challan.from_station_name} →{' '}
                                {challan.to_station_name}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Vehicle / Driver
                            </div>
                            <div className="font-medium">
                                {challan.vehicle_registration} ·{' '}
                                {challan.driver_name}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Packages / Weight (kg)
                            </div>
                            <div className="font-medium">
                                {challan.total_packages} /{' '}
                                {challan.total_weight_kg}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                To Pay / Paid
                            </div>
                            <div className="font-medium">
                                {challan.to_pay_amount} / {challan.paid_amount}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                TOTAL
                            </div>
                            <div className="font-medium">
                                {challan.total_amount}
                            </div>
                        </div>
                    </div>

                    <div className="overflow-x-auto border-t">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Bilty
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Customer
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        To
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Pkgs
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Weight
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Freight
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Term
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {challan.lines.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {line.lr_number}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.billing_customer_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.to_station_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.packages}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.weight_kg}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.freight_amount}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.freight_payment_term ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {permissions.can_cancel && urls.cancel ? (
                        <form
                            className="space-y-2 border-t p-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                cancelForm.post(urls.cancel!);
                            }}
                        >
                            <Label htmlFor="cancellation_remark" required>
                                Cancel remark
                            </Label>
                            <Input
                                id="cancellation_remark"
                                value={cancelForm.data.cancellation_remark}
                                onChange={(event) =>
                                    cancelForm.setData(
                                        'cancellation_remark',
                                        event.target.value,
                                    )
                                }
                            />
                            {cancelForm.errors.cancellation_remark ? (
                                <p className="text-sm text-destructive">
                                    {cancelForm.errors.cancellation_remark}
                                </p>
                            ) : null}
                            <Button
                                type="submit"
                                variant="destructive"
                                size="sm"
                                disabled={cancelForm.processing}
                            >
                                Cancel Challan
                            </Button>
                        </form>
                    ) : null}
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
