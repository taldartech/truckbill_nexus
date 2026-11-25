import { Head, Link } from '@inertiajs/react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';

type PendingDeliveryRow = {
    id: string;
    inward_number: string | null;
    lr_number: string | null;
    billing_customer_name: string | null;
    to_station_name: string | null;
    packages: string;
    weight_mt: string;
    create_dr_url: string;
};

type Props = {
    permissions: { can_create_dr: boolean };
    rows: PendingDeliveryRow[];
    totals: {
        count: number;
        packages: string;
        weight_kg: string;
    };
    urls: {
        index: string;
        in_transit: string;
        create_dr: string;
    };
};

export default function PartloadPendingDeliveryIndex({
    permissions,
    rows,
    totals,
    urls,
}: Props) {
    return (
        <>
            <Head title="Pending Delivery" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Pending Delivery"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link href={urls.in_transit}>In Transit</Link>
                            </Button>
                            {permissions.can_create_dr ? (
                                <Button
                                    asChild
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    <Link href={urls.create_dr}>
                                        Create Delivery Receipt
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                >
                    <div className="mb-3 flex flex-wrap gap-4 px-1 text-sm text-muted-foreground">
                        <span>
                            Pending: <strong>{totals.count}</strong>
                        </span>
                        <span>
                            Packages: <strong>{totals.packages}</strong>
                        </span>
                        <span>
                            Weight:{' '}
                            <strong>
                                {(Number(totals.weight_kg) / 1000).toFixed(3)}{' '}
                                MT
                            </strong>
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[960px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Inward
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Bilty
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Customer
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        To Station
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Packages
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Weight (MT)
                                    </th>
                                    <th className="px-3 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No Delivery-disposition Inward lines
                                            are awaiting a Delivery Receipt.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {row.inward_number ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.lr_number ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.billing_customer_name ??
                                                    '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.to_station_name ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.packages}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.weight_mt}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {permissions.can_create_dr ? (
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={
                                                                row.create_dr_url
                                                            }
                                                        >
                                                            Create DR
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
