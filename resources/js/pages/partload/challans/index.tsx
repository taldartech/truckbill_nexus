import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type ChallanRow = {
    id: string;
    challan_number: string;
    challan_date: string | null;
    status: string;
    status_label: string;
    from_station_name: string | null;
    to_station_name: string | null;
    vehicle_registration: string | null;
    driver_name: string | null;
    line_count: number;
    total_packages: string;
    total_amount: string;
    urls: { show: string };
};

type Props = {
    filters: { status: string };
    permissions: { can_view: boolean; can_create: boolean };
    statusOptions: Array<{ value: string; label: string }>;
    challans: {
        data: ChallanRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string; create: string };
};

export default function PartloadChallansIndex({
    filters,
    permissions,
    statusOptions,
    challans,
    urls,
}: Props) {
    return (
        <>
            <Head title="Partload Challans" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Partload Challans"
                    toolbar={
                        permissions.can_create ? (
                            <Button asChild className="bg-[#2FB36F] text-white hover:bg-[#279960]">
                                <Link href={urls.create}>Create Challan</Link>
                            </Button>
                        ) : null
                    }
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const status = (
                                form.elements.namedItem(
                                    'status',
                                ) as HTMLSelectElement
                            ).value;
                            router.get(
                                urls.index,
                                { status: status || undefined },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                defaultValue={filters.status}
                            >
                                <option value="">All</option>
                                {statusOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Apply
                        </Button>
                    </AppIndexFilters>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[960px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Challan</th>
                                    <th className="px-3 py-2 font-medium">Date</th>
                                    <th className="px-3 py-2 font-medium">Route</th>
                                    <th className="px-3 py-2 font-medium">Vehicle</th>
                                    <th className="px-3 py-2 font-medium">Lines</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 font-medium">Total</th>
                                    <th className="px-3 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {challans.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-3 py-8 text-center text-muted-foreground"
                                        >
                                            No Challans yet. Consolidate eligible
                                            Partload Bilties to create one.
                                        </td>
                                    </tr>
                                ) : (
                                    challans.data.map((row) => (
                                        <tr key={row.id} className="border-b last:border-0">
                                            <td className="px-3 py-2 font-medium">
                                                {row.challan_number}
                                            </td>
                                            <td className="px-3 py-2">{row.challan_date}</td>
                                            <td className="px-3 py-2">
                                                {row.from_station_name} → {row.to_station_name}
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.vehicle_registration}
                                                {row.driver_name ? (
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.driver_name}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-3 py-2">{row.line_count}</td>
                                            <td className="px-3 py-2">{row.status_label}</td>
                                            <td className="px-3 py-2">{row.total_amount}</td>
                                            <td className="px-3 py-2 text-right">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={row.urls.show}>View</Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-4 py-3">
                        <TablePagination
                            baseUrl={urls.index}
                            meta={challans.meta}
                            query={{ status: filters.status || undefined }}
                        />
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
