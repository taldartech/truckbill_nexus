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

type GstRateRow = {
    id: string;
    label: string;
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
                id: 'rate',
                header: 'GST rate',
                priority: 0,
                cell: (row) => (
                    <>
                        <div className="font-medium">{row.label}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.rate_percent}%
                            {row.version > 1 ? ` · v${row.version}` : ''}
                        </div>
                    </>
                ),
            },
            {
                id: 'status',
                header: 'Status',
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
            <PageShell title="GST rates" description={compliance_note}>
                <PortalFlashBanner message={flash?.success} />
                <PortalErrorList
                    errors={errors}
                    keys={['rate_percent', 'remark']}
                />

                <Form action={store_url} method="post">
                    <FormSection title="Create GST rate" columns={2}>
                        <div className="space-y-2">
                            <Label htmlFor="rate_percent" required>
                                GST rate (%)
                            </Label>
                            <Input
                                id="rate_percent"
                                name="rate_percent"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                required
                                placeholder="18"
                            />
                        </div>
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    defaultChecked
                                    className="size-4 rounded border border-input"
                                />
                                Active
                            </label>
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
                                Create GST rate
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="GST rates"
                        columns={columns}
                        rows={rates}
                        rowKey={(row) => row.id}
                        emptyTitle="No GST rates yet"
                        emptyDescription="Create a rate using the form above."
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
                                        aria-label={`Deactivate remark for ${rate.rate_percent}%`}
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
