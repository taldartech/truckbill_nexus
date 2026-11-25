import { Head, Link, router } from '@inertiajs/react';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Props = {
    filters: { status: string };
    permissions: { can_create: boolean };
    statusOptions: Array<{ value: string; label: string }>;
    mainChallans: {
        data: Array<{
            id: string;
            main_challan_number: string;
            main_challan_date: string | null;
            status_label: string;
            from_station_name: string | null;
            to_station_name: string | null;
            vehicle_registration: string | null;
            challan_count: number;
            total_amount: string;
            urls: { show: string };
        }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string; create: string };
};

export default function PartloadMainChallansIndex({
    filters,
    permissions,
    statusOptions,
    mainChallans,
    urls,
}: Props) {
    return (
        <>
            <Head title="Main Challans" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Main Challans"
                    toolbar={
                        permissions.can_create ? (
                            <Button
                                asChild
                                size="sm"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                <Link href={urls.create}>Add Main Challan</Link>
                            </Button>
                        ) : null
                    }
                >
                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            router.get(
                                urls.index,
                                {
                                    status:
                                        (
                                            form.elements.namedItem(
                                                'status',
                                            ) as HTMLSelectElement
                                        ).value || undefined,
                                },
                                { preserveState: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                name="status"
                                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                defaultValue={filters.status}
                            >
                                <option value="">All</option>
                                {statusOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
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
                        <table className="w-full min-w-[840px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Number
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Date
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Route
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Vehicle
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Challans
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {mainChallans.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            <Link
                                                href={row.urls.show}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {row.main_challan_number}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.main_challan_date}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.from_station_name} →{' '}
                                            {row.to_station_name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.vehicle_registration}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.challan_count}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.status_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.total_amount}
                                        </td>
                                    </tr>
                                ))}
                                {mainChallans.data.length === 0 ? (
                                    <tr>
                                        <td
                                            className="px-3 py-6 text-muted-foreground"
                                            colSpan={7}
                                        >
                                            No Main Challans found.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        baseUrl={urls.index}
                        meta={mainChallans.meta}
                        query={{ status: filters.status || undefined }}
                    />
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
