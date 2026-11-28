import { formatDisplayDate } from '@/lib/dates';
import { LrfSection } from '@/pages/branch/lorry-receipts/lr-form-sections';

type LorryReceiptSummary = {
    id: string;
    lr_number: string | null;
    lr_date: string | null;
    customer_name: string | null;
    vehicle_registration: string | null;
    vehicle_owner_name: string | null;
    loading_station: string | null;
    unloading_station: string | null;
};

function dash(value: string | null | undefined): string {
    return value !== null && value !== undefined && value !== '' ? value : '—';
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0 space-y-0.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm font-medium break-words text-foreground">
                {value}
            </p>
        </div>
    );
}

type Props = {
    lorryReceipt: LorryReceiptSummary;
    payeeName: string | null;
    goodsLabel: string;
    unitsLabel: string;
    actualWeightLabel: string;
    chargedWeightLabel: string;
    isEdit: boolean;
};

/** Legacy `freight_bookings/create|edit` linked bilty context (read-only). */
export function LinkedBiltyContextPanel({
    lorryReceipt,
    payeeName,
    goodsLabel,
    unitsLabel,
    actualWeightLabel,
    chargedWeightLabel,
    isEdit,
}: Props) {
    const route = `${dash(lorryReceipt.loading_station)} → ${dash(lorryReceipt.unloading_station)}`;

    return (
        <div data-testid="lorry-hire-section-linked-bilty">
            <LrfSection
                step={1}
                title="Linked Bilty"
                description={
                    isEdit
                        ? 'Inherited from the linked Bilty — change Payee, Truck or shipment details through Bilty edit'
                        : 'Linked bilty is shown for reference. Complete hire charges to continue.'
                }
                tone="receipt"
            >
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Metric
                        label="Bilty Number"
                        value={dash(lorryReceipt.lr_number)}
                    />
                    <Metric
                        label="Bilty Date"
                        value={formatDisplayDate(lorryReceipt.lr_date)}
                    />
                    <Metric
                        label="Customer"
                        value={dash(lorryReceipt.customer_name)}
                    />
                    <Metric
                        label="Truck Number"
                        value={dash(lorryReceipt.vehicle_registration)}
                    />
                    <Metric
                        label="Truck Owner"
                        value={dash(lorryReceipt.vehicle_owner_name)}
                    />
                    <Metric label="Payee" value={dash(payeeName)} />
                    <Metric label="Route" value={route} />
                    <Metric label="Goods" value={goodsLabel} />
                    <Metric label="Units" value={unitsLabel} />
                    <Metric label="Actual Weight" value={actualWeightLabel} />
                    <Metric label="Charged Weight" value={chargedWeightLabel} />
                </div>
            </LrfSection>
        </div>
    );
}
