import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type GoodsRow = {
    id: string;
    goods_description: string | null;
    freight_rate_type: string;
    freight_rate: string | null;
    freight_amount: string | null;
    loading_weight: string | null;
};

type Props = {
    lorryReceipt: {
        id: string;
        lr_number: string | null;
        lr_date: string | null;
        from_station_name: string | null;
        to_station_name: string | null;
        consignor_name: string | null;
        consignee_name: string | null;
        vehicle_registration: string | null;
    };
    goodsRows: GoodsRow[];
    urls: {
        store: string;
        lr_show: string;
        index: string;
    };
};

export default function LorryHireCreate({ lorryReceipt, goodsRows, urls }: Props) {
    return (
        <>
            <Head title="Create Lorry Hire" />
            <PageShell title="Create Lorry Hire">
                <Link
                    href={urls.lr_show}
                    className="mb-4 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="h-4 w-4" />
                    Back to LR {lorryReceipt.lr_number}
                </Link>

                <div className="rounded-lg border border-border bg-card p-4">
                    <p className="text-sm text-muted-foreground">
                        Broker LR · {lorryReceipt.lr_number} ·{' '}
                        {lorryReceipt.lr_date}
                    </p>
                    <p className="mt-1 text-sm">
                        {lorryReceipt.from_station_name} →{' '}
                        {lorryReceipt.to_station_name} · Market vehicle{' '}
                        {lorryReceipt.vehicle_registration ?? '—'}
                    </p>
                </div>

                <Form
                    action={urls.store}
                    method="post"
                    className="mt-6 space-y-6"
                >
                    {({ processing }) => (
                        <>
                            <div className="space-y-4">
                                <h2 className="text-sm font-semibold">
                                    Freight lines
                                </h2>
                                {goodsRows.map((row, index) => (
                                    <div
                                        key={row.id}
                                        className="grid gap-3 rounded-md border border-border p-4 md:grid-cols-2"
                                    >
                                        <input
                                            type="hidden"
                                            name={`goods_lines[${index}][lr_goods_row_id]`}
                                            value={row.id}
                                        />
                                        <input
                                            type="hidden"
                                            name={`goods_lines[${index}][rate_type]`}
                                            value={row.freight_rate_type}
                                        />
                                        <input
                                            type="hidden"
                                            name={`goods_lines[${index}][rate]`}
                                            value={
                                                row.freight_rate ??
                                                row.freight_amount ??
                                                ''
                                            }
                                        />
                                        <input
                                            type="hidden"
                                            name={`goods_lines[${index}][is_fixed_amount]`}
                                            value={
                                                row.freight_rate_type ===
                                                'fixed_amount'
                                                    ? '1'
                                                    : '0'
                                            }
                                        />
                                        <div>
                                            <p className="text-sm font-medium">
                                                {row.goods_description ??
                                                    'Goods line'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Rate type: {row.freight_rate_type}{' '}
                                                · Rate:{' '}
                                                {row.freight_amount ??
                                                    row.freight_rate ??
                                                    '—'}
                                            </p>
                                        </div>
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor={`loading_weight_${index}`}
                                            >
                                                Loading weight
                                            </Label>
                                            <Input
                                                id={`loading_weight_${index}`}
                                                name={`goods_lines[${index}][loading_weight]`}
                                                defaultValue={
                                                    row.loading_weight ?? ''
                                                }
                                                type="number"
                                                step="0.001"
                                                min="0"
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="remarks">Remarks</Label>
                                <Input
                                    id="remarks"
                                    name="remarks"
                                    placeholder="Optional remarks"
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                {processing
                                    ? 'Creating…'
                                    : 'Create Lorry Hire'}
                            </Button>
                        </>
                    )}
                </Form>
            </PageShell>
        </>
    );
}