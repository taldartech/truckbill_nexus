import { Form, Head, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
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
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <>
            <Head title="GST rate master" />
            <PageShell title="GST rate versions" description={compliance_note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList errors={errors} keys={['code', 'remark']} />

                <Form action={store_url} method="post">
                    <FormSection title="Create version" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                name="code"
                                required
                                className="font-mono"
                                placeholder="GST18"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                placeholder="GST 18%"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="rate_percent">Rate percent</Label>
                            <Input
                                id="rate_percent"
                                name="rate_percent"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                required
                                placeholder="18.000"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="effective_from">
                                Effective from
                            </Label>
                            <Input
                                id="effective_from"
                                name="effective_from"
                                type="date"
                                required
                            />
                        </div>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="remark">Remark</Label>
                            <Input
                                id="remark"
                                name="remark"
                                placeholder="Optional audit remark"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Button type="submit" variant="default">
                                Create GST version
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
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
                                    <Input
                                        name="remark"
                                        required
                                        placeholder="Deactivate remark"
                                        aria-label={`Deactivate remark for ${rate.code} v${rate.version}`}
                                        className="h-8 max-w-[10rem] text-xs"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
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
            </PageShell>
        </>
    );
}
