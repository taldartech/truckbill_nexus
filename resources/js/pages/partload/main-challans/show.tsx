import { Form, Head, Link, router } from '@inertiajs/react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    permissions: { can_dispatch: boolean; can_cancel: boolean };
    mainChallan: {
        main_challan_number: string;
        main_challan_date: string | null;
        status_label: string;
        from_station_name: string | null;
        to_station_name: string | null;
        vehicle_registration: string | null;
        driver_name: string | null;
        total_packages: string;
        total_weight_kg: string;
        to_pay_amount: string;
        total_amount: string;
        remark: string | null;
        lines: Array<{
            id: string;
            challan_number: string | null;
            challan_status: string | null;
            from_station_name: string | null;
            to_station_name: string | null;
            vehicle_registration: string | null;
            urls: { show: string | null };
        }>;
    };
    urls: { index: string; dispatch: string | null; cancel: string | null };
};

export default function PartloadMainChallanShow({
    permissions,
    mainChallan,
    urls,
}: Props) {
    return (
        <>
            <Head title={`Main Challan ${mainChallan.main_challan_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Main Challan ${mainChallan.main_challan_number}`}
                    toolbar={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.index}>Register</Link>
                            </Button>
                            {urls.dispatch && permissions.can_dispatch ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => router.post(urls.dispatch!)}
                                >
                                    Dispatch
                                </Button>
                            ) : null}
                        </div>
                    }
                >
                    <div className="grid gap-3 p-4 text-sm md:grid-cols-3">
                        <div>
                            <div className="text-muted-foreground">Date</div>
                            <div className="font-medium">
                                {mainChallan.main_challan_date}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground">Status</div>
                            <div className="font-medium">
                                {mainChallan.status_label}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground">Route</div>
                            <div className="font-medium">
                                {mainChallan.from_station_name} →{' '}
                                {mainChallan.to_station_name}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground">Vehicle</div>
                            <div className="font-medium">
                                {mainChallan.vehicle_registration}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground">Driver</div>
                            <div className="font-medium">
                                {mainChallan.driver_name}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground">Total</div>
                            <div className="font-medium">
                                {mainChallan.total_amount}
                            </div>
                        </div>
                    </div>

                    <div className="overflow-x-auto border-t">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Challan
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Route
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Vehicle
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {mainChallan.lines.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            {line.urls.show ? (
                                                <Link
                                                    href={line.urls.show}
                                                    className="text-primary hover:underline"
                                                >
                                                    {line.challan_number}
                                                </Link>
                                            ) : (
                                                line.challan_number
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.from_station_name} →{' '}
                                            {line.to_station_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.vehicle_registration}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.challan_status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {urls.cancel && permissions.can_cancel ? (
                        <Form
                            action={urls.cancel}
                            method="post"
                            className="space-y-3 border-t p-4"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor="cancellation_remark"
                                            required
                                        >
                                            Cancel Remark
                                        </Label>
                                        <Input
                                            id="cancellation_remark"
                                            name="cancellation_remark"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Cancel Main Challan
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
