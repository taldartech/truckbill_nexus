import { Head, Link, router } from '@inertiajs/react';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type HireRow = {
    id: string;
    hire_number: string;
    status: string;
    lr_number: string | null;
    vehicle_registration: string | null;
    transporter_name: string | null;
    from_station_name: string | null;
    to_station_name: string | null;
    freight_total: string | null;
    outstanding_amount: string | null;
    created_at: string | null;
    urls: { show: string };
};

type Props = {
    filters: { status: string };
    permissions: { can_view: boolean; can_create: boolean };
    statusOptions: Array<{ value: string; label: string }>;
    lorryHires: {
        data: HireRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string };
};

function statusLabel(status: string): string {
    switch (status) {
        case 'active':
            return 'Active';
        case 'pending':
            return 'Pending';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

export default function LorryHiresIndex({
    filters,
    statusOptions,
    lorryHires,
    urls,
}: Props) {
    return (
        <>
            <Head title="Broker Lorry Hires" />
            <PageShell title="Broker Lorry Hires">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    Lorry Hires are created from Broker (market vehicle) Lorry
                    Receipts. Open a Broker LR and use Create Lorry Hire.
                </p>

                <form
                    className="flex flex-wrap items-end gap-3"
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
                            defaultValue={filters.status}
                            className="h-9 min-w-[10rem] rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All</option>
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button
                        type="submit"
                        className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                    >
                        Filter
                    </Button>
                </form>

                <div className="overflow-hidden rounded-lg border border-border">
                    <table className="min-w-full text-sm">
                        <thead className="bg-muted/40 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Hire #</th>
                                <th className="px-4 py-3 font-medium">LR</th>
                                <th className="px-4 py-3 font-medium">Route</th>
                                <th className="px-4 py-3 font-medium">Vehicle</th>
                                <th className="px-4 py-3 font-medium">Transporter</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Freight</th>
                                <th className="px-4 py-3 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {lorryHires.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No Lorry Hires yet. Create one from a
                                        Broker LR.
                                    </td>
                                </tr>
                            ) : (
                                lorryHires.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {row.hire_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.lr_number ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {row.from_station_name ?? '—'} →{' '}
                                            {row.to_station_name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.vehicle_registration ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.transporter_name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.status}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.freight_total ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={row.urls.show}
                                                className="font-medium text-[#2FB36F] hover:underline"
                                            >
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </>
    );
}