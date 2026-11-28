import { formatDisplayDate } from '@/lib/dates';
import { LrfLinkedHero } from '@/pages/branch/lorry-receipts/lr-form-sections';

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

type Props = {
    lorryReceipt: LorryReceiptSummary;
    payeeName: string | null;
    goodsLabel: string;
    unitsLabel: string;
    actualWeightLabel: string;
    chargedWeightLabel: string;
    isEdit: boolean;
};

/** Legacy `freight_bookings/create|edit` linked bilty panel (`lrf-linked`). */
export function LinkedBiltyContextPanel({
    lorryReceipt,
    payeeName,
    goodsLabel,
    unitsLabel,
    chargedWeightLabel,
}: Props) {
    return (
        <div data-testid="lorry-hire-section-linked-bilty">
            <LrfLinkedHero
                biltyNumber={dash(lorryReceipt.lr_number)}
                routeFrom={dash(lorryReceipt.loading_station)}
                routeTo={dash(lorryReceipt.unloading_station)}
                kpis={[
                    { label: 'Goods', value: goodsLabel },
                    { label: 'Weight', value: chargedWeightLabel },
                    {
                        label: 'Bilty Date',
                        value: formatDisplayDate(lorryReceipt.lr_date),
                    },
                    { label: 'Units', value: unitsLabel, accent: true },
                ]}
                parties={[
                    {
                        label: 'Customer',
                        value: dash(lorryReceipt.customer_name),
                    },
                    { label: 'Payee', value: dash(payeeName) },
                    {
                        label: 'Consignor',
                        value: '—',
                    },
                    {
                        label: 'Consignee',
                        value: '—',
                    },
                ]}
                vehicleRoute={[
                    {
                        label: 'Truck',
                        value: dash(lorryReceipt.vehicle_registration),
                    },
                    {
                        label: 'Owner',
                        value: dash(lorryReceipt.vehicle_owner_name),
                    },
                    {
                        label: 'From',
                        value: dash(lorryReceipt.loading_station),
                    },
                    {
                        label: 'To',
                        value: dash(lorryReceipt.unloading_station),
                    },
                ]}
            />
        </div>
    );
}
