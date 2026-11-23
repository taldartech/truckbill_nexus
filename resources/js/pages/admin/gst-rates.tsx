import { Form, Head, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { formatDisplayDateSmart } from '@/lib/dates';

type GstRateRow = {
    id: string;
    code: string;
    name: string;
    rate_percent: string;
    version: number;
    is_active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    deactivate_url: string;
};

type Props = {
    compliance_note: string;
    store_url: string;
    rates: GstRateRow[];
};

export default function AdminGstRates({
    compliance_note,
    store_url,
    rates,
}: Props) {
    const { flash, errors } = usePage<{
        flash?: { success?: string | null };
        errors?: Record<string, string>;
    }>().props;

    const columns = useMemo<DataTableColumn<GstRateRow>[]>(
        () => [
            {
                id: 'code',
                header: 'Code',
                priority: 0,
                className: 'font-mono text-xs',
                cell: (row) => row.code,
            },
            {
                id: 'name',
                header: 'Name',
                priority: 2,
                cell: (row) => row.name,
            },
            {
                id: 'rate',
                header: 'Rate',
                priority: 2,
                cell: (row) => `${row.rate_percent}%`,
            },
            {
                id: 'version',
                header: 'Ver',
                priority: 3,
                cell: (row) => row.version,
            },
            {
                id: 'effective',
                header: 'Effective',
                priority: 2,
                className: 'text-xs',
                cell: (row) =>
                    row.effective_from
                        ? `${formatDisplayDateSmart(row.effective_from)}${row.effective_to ? ` → ${formatDisplayDateSmart(row.effective_to)}` : ''}`
                        : '—',
            },
            {
                id: 'active',
                header: 'Active',
                priority: 1,
                cell: (row) => (row.is_active ? 'Yes' : 'No'),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="GST rate master" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Internal · Tax masters
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        GST rate versions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {compliance_note}
                    </p>
                </div>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                {errors?.code || errors?.remark ? (
                    <p className="text-sm text-destructive" role="alert">
                        {errors.code || errors.remark}
                    </p>
                ) : null}

                <section className="space-y-3 rounded-xl border border-border p-4">
                    <h2 className="font-semibold">Create version</h2>
                    <Form
                        action={store_url}
                        method="post"
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <label className="flex flex-col gap-1 text-sm">
                            Code
                            <input
                                name="code"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2 font-mono text-sm"
                                placeholder="GST18"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Name
                            <input
                                name="name"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                                placeholder="GST 18%"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Rate percent
                            <input
                                name="rate_percent"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                                placeholder="18.000"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Effective from
                            <input
                                name="effective_from"
                                type="date"
                                required
                                className="rounded-md border border-border bg-background px-3 py-2"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm md:col-span-2">
                            Remark
                            <input
                                name="remark"
                                className="rounded-md border border-border bg-background px-3 py-2"
                                placeholder="Optional audit remark"
                            />
                        </label>
                        <div className="md:col-span-2">
                            <Button type="submit">Create GST version</Button>
                        </div>
                    </Form>
                </section>

                <ResponsiveDataTable
                    caption="GST rate versions"
                    columns={columns}
                    rows={rates}
                    rowKey={(row) => row.id}
                    emptyTitle="No GST rate versions yet"
                    emptyDescription="Create a version using the form above."
                    actions={(rate) =>
                        rate.is_active ? (
                            <Form
                                action={rate.deactivate_url}
                                method="post"
                                className="flex flex-wrap items-center justify-end gap-2"
                            >
                                <input
                                    name="remark"
                                    required
                                    placeholder="Deactivate remark"
                                    aria-label={`Deactivate remark for ${rate.code} v${rate.version}`}
                                    className="max-w-[8rem] rounded-md border border-border bg-background px-2 py-1 text-xs"
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                >
                                    Deactivate
                                </Button>
                            </Form>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )
                    }
                />
            </div>
        </>
    );
}
