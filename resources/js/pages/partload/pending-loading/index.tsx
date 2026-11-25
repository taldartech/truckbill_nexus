import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type PendingRow = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    billing_customer_name: string | null;
    consignor_name: string | null;
    consignee_name: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    packages: string;
    weight_mt: string;
    freight_total: string;
    freight_payment_term_label: string | null;
};

type Props = {
    filters: {
        from_station_id: string | null;
        to_station_id: string | null;
    };
    permissions: {
        can_view: boolean;
        can_create_challan: boolean;
    };
    rows: PendingRow[];
    totals: {
        count: number;
        packages: string;
        weight_kg: string;
        freight_total: string;
    };
    stationOptions: Array<{ id: string; name: string; code: string | null }>;
    urls: {
        index: string;
        create_challan: string;
        create_challan_filtered: string;
        booking_register: string;
    };
};

function formatInr(value: string): string {
    const amount = Number(value);
    if (Number.isNaN(amount)) {
        return value;
    }

    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        maximumFractionDigits: 2,
    }).format(amount);
}

export default function PartloadPendingLoadingIndex({
    filters,
    permissions,
    rows,
    totals,
    stationOptions,
    urls,
}: Props) {
    return (
        <>
            <Head title="Pending Loading" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Pending Loading"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link href={urls.booking_register}>
                                    Booking Register
                                </Link>
                            </Button>
                            {permissions.can_create_challan ? (
                                <Button
                                    asChild
                                    className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                                >
                                    <Link href={urls.create_challan_filtered}>
                                        Create Challan
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const fromStationId = (
                                form.elements.namedItem(
                                    'from_station_id',
                                ) as HTMLSelectElement
                            ).value;
                            const toStationId = (
                                form.elements.namedItem(
                                    'to_station_id',
                                ) as HTMLSelectElement
                            ).value;
                            router.get(
                                urls.index,
                                {
                                    from_station_id: fromStationId || undefined,
                                    to_station_id: toStationId || undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="from_station_id">
                                From Station
                            </Label>
                            <select
                                id="from_station_id"
                                name="from_station_id"
                                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                defaultValue={filters.from_station_id ?? ''}
                            >
                                <option value="">All</option>
                                {stationOptions.map((station) => (
                                    <option key={station.id} value={station.id}>
                                        {station.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="to_station_id">To Station</Label>
                            <select
                                id="to_station_id"
                                name="to_station_id"
                                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                defaultValue={filters.to_station_id ?? ''}
                            >
                                <option value="">All</option>
                                {stationOptions.map((station) => (
                                    <option key={station.id} value={station.id}>
                                        {station.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>

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
                        <span>
                            Freight:{' '}
                            <strong>{formatInr(totals.freight_total)}</strong>
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1080px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Bilty
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Date
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Customer
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Route
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Packages
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Weight (MT)
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Term
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Freight
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No Partload Bilties are pending
                                            loading.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {row.lr_number}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.lr_date ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.billing_customer_name ??
                                                    '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.from_station_name ?? '—'} →{' '}
                                                {row.to_station_name ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.packages}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.weight_mt}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.freight_payment_term_label ??
                                                    '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {formatInr(row.freight_total)}
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
