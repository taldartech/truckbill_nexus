import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type InTransitRow = {
    id: string;
    challan_number: string;
    challan_date: string | null;
    dispatched_at: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    vehicle_registration: string | null;
    driver_name: string | null;
    line_count: number;
    total_packages: string;
    total_weight_mt: string;
    total_amount: string;
    show_url: string;
};

type Props = {
    filters: {
        from_station_id: string | null;
        to_station_id: string | null;
    };
    rows: InTransitRow[];
    totals: {
        count: number;
        packages: string;
        weight_kg: string;
        amount: string;
    };
    stationOptions: Array<{ id: string; name: string }>;
    urls: {
        index: string;
        challans: string;
        pending_loading: string;
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

export default function PartloadInTransitIndex({
    filters,
    rows,
    totals,
    stationOptions,
    urls,
}: Props) {
    return (
        <>
            <Head title="In Transit" />
            <AppIndexPage>
                <AppIndexListCard
                    title="In Transit"
                    toolbar={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link href={urls.pending_loading}>
                                    Pending Loading
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={urls.challans}>Challans</Link>
                            </Button>
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
                            In transit: <strong>{totals.count}</strong>
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
                            Amount: <strong>{formatInr(totals.amount)}</strong>
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1080px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Challan
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Dispatched
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Route
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Vehicle
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Lines
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Packages
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Weight (MT)
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No Dispatched Challans are in
                                            transit.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {row.challan_number}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.dispatched_at ??
                                                    row.challan_date ??
                                                    '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.from_station_name ?? '—'} →{' '}
                                                {row.to_station_name ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.line_count}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.total_packages}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.total_weight_mt}
                                            </td>
                                            <td className="px-3 py-2">
                                                {formatInr(row.total_amount)}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <Link href={row.show_url}>
                                                        Open
                                                    </Link>
                                                </Button>
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
