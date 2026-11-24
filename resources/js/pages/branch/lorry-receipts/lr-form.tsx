import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import InputError from '@/components/input-error';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };
type NamedOption = { id: string; name: string; code?: string };
type VehicleOption = {
    id: string;
    registration_number: string;
    ownership: string | null;
    ownership_label?: string | null;
    label: string;
};

type GoodsRow = {
    goods_type_id: string;
    package_type_id: string;
    goods_description: string;
    package_count: string;
    weight_per_unit: string;
    actual_weight: string;
    charged_weight: string;
    freight_rate_type: string;
    freight_rate: string;
    goods_value: string;
};

type EwbRow = {
    ewb_number: string;
    ewb_date: string;
    valid_until: string;
};

type ChargeRow = {
    description: string;
    amount: string;
};

type LorryReceiptDetail = {
    id: string;
    lr_number: string;
    lr_date: string | null;
    workflow_context: string;
    from_text: string | null;
    to_text: string | null;
    from_station_id: string | null;
    to_station_id: string | null;
    consignor_party_id: string | null;
    consignee_party_id: string | null;
    goods_value: string | null;
    goods: Array<{
        goods_type_id: string | null;
        package_type_id: string | null;
        goods_description: string | null;
        package_count: string | null;
        actual_weight: string | null;
        charged_weight: string | null;
        freight_rate_type: string;
        freight_rate: string;
        goods_value: string | null;
    }>;
    eway_bills: Array<{
        ewb_number: string;
        ewb_date: string | null;
        valid_until: string | null;
    }>;
    additional_charges: Array<{
        description: string;
        amount: string;
    }>;
};

type Props = {
    mode: 'create' | 'edit';
    formMode?: 'truck' | 'partload';
    workflowContext: string | null;
    contextLabel: string;
    lorryReceipt: LorryReceiptDetail | null;
    formOptions: {
        rateTypeOptions: Option[];
        consignors: NamedOption[];
        consignees: NamedOption[];
        billingCustomers?: NamedOption[];
        stations: NamedOption[];
        goodsTypes: NamedOption[];
        packageTypes: NamedOption[];
        vehicles?: VehicleOption[];
    };
    urls: {
        index: string;
        store?: string;
        update?: string;
        show?: string;
    };
    permissions?: {
        can_view_freight?: boolean;
    };
};

type GoodsFieldSource =
    | 'weight_per_unit'
    | 'actual_weight'
    | 'package_count'
    | 'other';

const fieldClass =
    'flex h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';
const dateFieldClass = `${fieldClass} min-w-[9.5rem]`;

const sectionShellClass =
    'overflow-hidden rounded-xl border border-border bg-card shadow-xs';
const sectionHeaderClass =
    'flex items-center justify-between gap-3 border-b border-border bg-gradient-to-r from-[#2FB36F]/10 via-[#2D8CFF]/5 to-transparent px-4 py-3';
const sectionBodyClass = 'p-4';

function todayIsoLocal(): string {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function parseNumeric(value: string): number | null {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }
    const parsed = Number(trimmed);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatFixed(value: number, digits: number): string {
    return value.toFixed(digits);
}

function deriveWeightPerUnit(
    actualWeight: string,
    packageCount: string,
): string {
    const actual = parseNumeric(actualWeight);
    const units = parseNumeric(packageCount);
    if (actual === null || units === null || units === 0) {
        return '';
    }
    return formatFixed(actual / units, 3);
}

function computeFreightPreview(row: GoodsRow): number | null {
    const rate = parseNumeric(row.freight_rate);
    if (rate === null) {
        return null;
    }

    const units = parseNumeric(row.package_count);
    const actual = parseNumeric(row.actual_weight);
    const charged = parseNumeric(row.charged_weight);

    switch (row.freight_rate_type) {
        case 'fixed_amount':
            return rate;
        case 'per_package_unit':
            if (units === null) {
                return null;
            }
            return rate * units;
        case 'per_kg':
        case 'per_actual_weight':
            if (actual === null) {
                return null;
            }
            return rate * actual;
        case 'per_mt':
            if (actual === null) {
                return null;
            }
            return rate * (actual / 1000);
        case 'per_charged_weight':
            if (charged === null) {
                return null;
            }
            return rate * charged;
        default:
            return null;
    }
}

function formatPreviewAmount(value: number | null): string {
    if (value === null) {
        return '—';
    }
    return formatFixed(value, 2);
}

function sumNumericStrings(values: string[]): number {
    return values.reduce((total, raw) => {
        const n = parseNumeric(raw);
        return n === null ? total : total + n;
    }, 0);
}

const emptyGoods = (): GoodsRow => ({
    goods_type_id: '',
    package_type_id: '',
    goods_description: '',
    package_count: '1',
    weight_per_unit: '',
    actual_weight: '',
    charged_weight: '',
    freight_rate_type: 'per_mt',
    freight_rate: '0',
    goods_value: '',
});

function mapGoodsFromReceipt(goods: LorryReceiptDetail['goods']): GoodsRow[] {
    if (goods.length === 0) {
        return [emptyGoods()];
    }
    return goods.map((row) => {
        const packageCount = row.package_count ?? '';
        const actualWeight = row.actual_weight ?? '';
        return {
            goods_type_id: row.goods_type_id ?? '',
            package_type_id: row.package_type_id ?? '',
            goods_description: row.goods_description ?? '',
            package_count: packageCount,
            weight_per_unit: deriveWeightPerUnit(actualWeight, packageCount),
            actual_weight: actualWeight,
            charged_weight: row.charged_weight ?? '',
            freight_rate_type: row.freight_rate_type,
            freight_rate: row.freight_rate ?? '0',
            goods_value: row.goods_value ?? '',
        };
    });
}

function FormPanelWithAction({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className={sectionShellClass}>
            <header className={sectionHeaderClass}>
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    {title}
                </h2>
                {action}
            </header>
            <div className={sectionBodyClass}>{children}</div>
        </section>
    );
}

export default function LrForm({
    mode,
    formMode: formModeProp,
    workflowContext,
    contextLabel,
    lorryReceipt,
    formOptions,
    urls,
    permissions,
}: Props) {
    const mayViewFreight = permissions?.can_view_freight !== false;
    const formMode =
        formModeProp ?? (workflowContext === 'partload' ? 'partload' : 'truck');

    const [vehicleId, setVehicleId] = useState(
        (lorryReceipt as { vehicle_id?: string | null } | null)?.vehicle_id ??
            '',
    );
    const [billingCustomerId, setBillingCustomerId] = useState(
        (lorryReceipt as { billing_customer_party_id?: string | null } | null)
            ?.billing_customer_party_id ?? '',
    );
    const [goods, setGoods] = useState<GoodsRow[]>(() =>
        lorryReceipt?.goods?.length
            ? mapGoodsFromReceipt(lorryReceipt.goods)
            : [emptyGoods()],
    );
    const [ewbs, setEwbs] = useState<EwbRow[]>(() =>
        lorryReceipt?.eway_bills?.length
            ? lorryReceipt.eway_bills.map((row) => ({
                  ewb_number: row.ewb_number,
                  ewb_date: row.ewb_date ?? '',
                  valid_until: row.valid_until ?? '',
              }))
            : [],
    );
    const [charges, setCharges] = useState<ChargeRow[]>(() =>
        lorryReceipt?.additional_charges?.length
            ? lorryReceipt.additional_charges.map((row) => ({
                  description: row.description,
                  amount: row.amount,
              }))
            : [],
    );

    const goodsSyncGuard = useRef<Record<number, GoodsFieldSource | null>>({});

    const selectedVehicle = (formOptions.vehicles ?? []).find(
        (vehicle) => vehicle.id === vehicleId,
    );
    const resolvedContext =
        formMode === 'partload'
            ? 'partload'
            : mode === 'edit'
              ? (workflowContext ?? 'broker')
              : selectedVehicle?.ownership === 'own'
                ? 'own_fleet'
                : selectedVehicle?.ownership === 'market'
                  ? 'broker'
                  : null;

    const needsStations =
        resolvedContext === 'own_fleet' || resolvedContext === 'partload';
    const needsParties =
        resolvedContext === 'broker' || resolvedContext === 'partload';
    const needsTextLocations = resolvedContext === 'broker';
    const partiesOptional = resolvedContext === 'own_fleet';
    const showVehicle = formMode === 'truck';

    const action = mode === 'create' ? urls.store! : urls.update!;
    const method = mode === 'create' ? 'post' : 'patch';
    const title =
        mode === 'create'
            ? 'Create Bilty'
            : `Edit Bilty ${lorryReceipt?.lr_number ?? ''}`;

    const ownershipBadge = useMemo(() => {
        if (!selectedVehicle?.ownership) {
            return null;
        }
        if (selectedVehicle.ownership === 'own') {
            return (
                <Badge variant="secondary" className="shrink-0">
                    Own
                </Badge>
            );
        }
        if (selectedVehicle.ownership === 'market') {
            return (
                <Badge variant="outline" className="shrink-0">
                    Market
                </Badge>
            );
        }
        return null;
    }, [selectedVehicle?.ownership]);

    const applyGoodsField = useCallback(
        (index: number, patch: Partial<GoodsRow>, source: GoodsFieldSource) => {
            goodsSyncGuard.current[index] = source;
            setGoods((current) =>
                current.map((row, rowIndex) => {
                    if (rowIndex !== index) {
                        return row;
                    }

                    const next: GoodsRow = { ...row, ...patch };

                    if (source === 'weight_per_unit') {
                        const units = parseNumeric(next.package_count);
                        const wpu = parseNumeric(next.weight_per_unit);
                        if (units !== null && wpu !== null) {
                            next.actual_weight = formatFixed(units * wpu, 3);
                        }
                    } else if (source === 'actual_weight') {
                        const units = parseNumeric(next.package_count);
                        const actual = parseNumeric(next.actual_weight);
                        if (units !== null && units !== 0 && actual !== null) {
                            next.weight_per_unit = formatFixed(
                                actual / units,
                                3,
                            );
                        }
                    } else if (source === 'package_count') {
                        const units = parseNumeric(next.package_count);
                        const actual = parseNumeric(next.actual_weight);
                        if (units !== null && units !== 0 && actual !== null) {
                            next.weight_per_unit = formatFixed(
                                actual / units,
                                3,
                            );
                        }
                    }

                    return next;
                }),
            );
            goodsSyncGuard.current[index] = null;
        },
        [],
    );

    const summary = useMemo(() => {
        const totalUnits = sumNumericStrings(
            goods.map((row) => row.package_count),
        );
        const totalActual = sumNumericStrings(
            goods.map((row) => row.actual_weight),
        );
        const totalCharged = sumNumericStrings(
            goods.map((row) => row.charged_weight),
        );
        const totalGoodsValue = sumNumericStrings(
            goods.map((row) => row.goods_value),
        );
        const totalFreight = goods.reduce((total, row) => {
            const preview = computeFreightPreview(row);
            return preview === null ? total : total + preview;
        }, 0);
        const totalAdditional = sumNumericStrings(
            charges.map((row) => row.amount),
        );

        return {
            totalUnits: formatFixed(totalUnits, 3),
            totalActual: formatFixed(totalActual, 3),
            totalCharged: formatFixed(totalCharged, 3),
            totalGoodsValue: formatFixed(totalGoodsValue, 2),
            totalFreight: formatFixed(totalFreight, 2),
            totalAdditional: formatFixed(totalAdditional, 2),
            totalAmount: formatFixed(totalFreight + totalAdditional, 2),
        };
    }, [charges, goods]);

    return (
        <>
            <Head title={title} />
            <PageShell
                width="full"
                title={title}
                description={contextLabel}
                actions={
                    <AppIconAction
                        icon={ArrowLeft}
                        label="Back"
                        href={urls.show ?? urls.index}
                        variant="outline"
                    />
                }
            >
                <datalist id="stations-list">
                    {formOptions.stations.map((station) => (
                        <option key={station.id} value={station.name} />
                    ))}
                </datalist>

                <Form
                    action={action}
                    method={method}
                    className="w-full space-y-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="form_mode"
                                value={formMode}
                            />
                            {resolvedContext ? (
                                <input
                                    type="hidden"
                                    name="workflow_context"
                                    value={resolvedContext}
                                />
                            ) : null}

                            <FormSection title="Section A" columns={3}>
                                {mode === 'create' ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="lr_number">
                                            Bilty No.
                                        </Label>
                                        <Input
                                            id="lr_number"
                                            name="lr_number"
                                            placeholder="Auto-generated if left blank"
                                        />
                                        <InputError
                                            message={errors.lr_number}
                                        />
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <Label htmlFor="lr_number_display">
                                            Bilty No.
                                        </Label>
                                        <Input
                                            id="lr_number_display"
                                            value={
                                                lorryReceipt?.lr_number ?? ''
                                            }
                                            disabled
                                            readOnly
                                        />
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="lr_date">Date</Label>
                                    <Input
                                        id="lr_date"
                                        name="lr_date"
                                        type="date"
                                        className={dateFieldClass}
                                        defaultValue={
                                            mode === 'create'
                                                ? todayIsoLocal()
                                                : (lorryReceipt?.lr_date ?? '')
                                        }
                                    />
                                    <InputError message={errors.lr_date} />
                                </div>
                                {showVehicle ? (
                                    <div className="space-y-2">
                                        <div className="flex items-center gap-2">
                                            <Label htmlFor="vehicle_id">
                                                Truck
                                            </Label>
                                            {ownershipBadge}
                                        </div>
                                        <select
                                            id="vehicle_id"
                                            name="vehicle_id"
                                            className={fieldClass}
                                            value={vehicleId}
                                            onChange={(e) =>
                                                setVehicleId(e.target.value)
                                            }
                                            required={mode === 'create'}
                                            disabled={mode === 'edit'}
                                        >
                                            <option value="">
                                                Select vehicle…
                                            </option>
                                            {(formOptions.vehicles ?? []).map(
                                                (vehicle) => (
                                                    <option
                                                        key={vehicle.id}
                                                        value={vehicle.id}
                                                    >
                                                        {vehicle.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={errors.vehicle_id}
                                        />
                                        <InputError
                                            message={errors.workflow_context}
                                        />
                                    </div>
                                ) : null}
                                <div className="space-y-2">
                                    <Label htmlFor="billing_customer_party_id">
                                        Billing Customer
                                    </Label>
                                    <select
                                        id="billing_customer_party_id"
                                        name="billing_customer_party_id"
                                        className={fieldClass}
                                        value={billingCustomerId}
                                        onChange={(e) =>
                                            setBillingCustomerId(e.target.value)
                                        }
                                    >
                                        <option value="">Optional…</option>
                                        {(
                                            formOptions.billingCustomers ?? []
                                        ).map((party) => (
                                            <option
                                                key={party.id}
                                                value={party.id}
                                            >
                                                {party.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={
                                            errors.billing_customer_party_id
                                        }
                                    />
                                </div>
                            </FormSection>

                            {needsTextLocations ||
                            needsStations ||
                            needsParties ||
                            partiesOptional ? (
                                <FormSection
                                    title="Parties & Route"
                                    columns={1}
                                >
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-3 rounded-lg border border-border/70 p-3">
                                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                Loading
                                            </p>
                                            {needsParties || partiesOptional ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="consignor_party_id">
                                                        Consignor
                                                        {needsParties
                                                            ? ' *'
                                                            : ''}
                                                    </Label>
                                                    <select
                                                        id="consignor_party_id"
                                                        name="consignor_party_id"
                                                        className={fieldClass}
                                                        defaultValue={
                                                            lorryReceipt?.consignor_party_id ??
                                                            ''
                                                        }
                                                        required={needsParties}
                                                    >
                                                        <option value="">
                                                            Select
                                                        </option>
                                                        {formOptions.consignors.map(
                                                            (party) => (
                                                                <option
                                                                    key={
                                                                        party.id
                                                                    }
                                                                    value={
                                                                        party.id
                                                                    }
                                                                >
                                                                    {party.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors.consignor_party_id
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                            {needsTextLocations ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="from_text">
                                                        Loading Station
                                                    </Label>
                                                    <Input
                                                        id="from_text"
                                                        name="from_text"
                                                        list="stations-list"
                                                        defaultValue={
                                                            lorryReceipt?.from_text ??
                                                            ''
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.from_text
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                            {needsStations ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="from_station_id">
                                                        Loading Station *
                                                    </Label>
                                                    <select
                                                        id="from_station_id"
                                                        name="from_station_id"
                                                        className={fieldClass}
                                                        defaultValue={
                                                            lorryReceipt?.from_station_id ??
                                                            ''
                                                        }
                                                        required
                                                    >
                                                        <option value="">
                                                            Select
                                                        </option>
                                                        {formOptions.stations.map(
                                                            (station) => (
                                                                <option
                                                                    key={
                                                                        station.id
                                                                    }
                                                                    value={
                                                                        station.id
                                                                    }
                                                                >
                                                                    {
                                                                        station.name
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors.from_station_id
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                        </div>
                                        <div className="space-y-3 rounded-lg border border-border/70 p-3">
                                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                Unloading
                                            </p>
                                            {needsParties || partiesOptional ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="consignee_party_id">
                                                        Consignee
                                                        {needsParties
                                                            ? ' *'
                                                            : ''}
                                                    </Label>
                                                    <select
                                                        id="consignee_party_id"
                                                        name="consignee_party_id"
                                                        className={fieldClass}
                                                        defaultValue={
                                                            lorryReceipt?.consignee_party_id ??
                                                            ''
                                                        }
                                                        required={needsParties}
                                                    >
                                                        <option value="">
                                                            Select
                                                        </option>
                                                        {formOptions.consignees.map(
                                                            (party) => (
                                                                <option
                                                                    key={
                                                                        party.id
                                                                    }
                                                                    value={
                                                                        party.id
                                                                    }
                                                                >
                                                                    {party.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors.consignee_party_id
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                            {needsTextLocations ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="to_text">
                                                        Unloading Station
                                                    </Label>
                                                    <Input
                                                        id="to_text"
                                                        name="to_text"
                                                        list="stations-list"
                                                        defaultValue={
                                                            lorryReceipt?.to_text ??
                                                            ''
                                                        }
                                                    />
                                                    <InputError
                                                        message={errors.to_text}
                                                    />
                                                </div>
                                            ) : null}
                                            {needsStations ? (
                                                <div className="space-y-2">
                                                    <Label htmlFor="to_station_id">
                                                        Unloading Station *
                                                    </Label>
                                                    <select
                                                        id="to_station_id"
                                                        name="to_station_id"
                                                        className={fieldClass}
                                                        defaultValue={
                                                            lorryReceipt?.to_station_id ??
                                                            ''
                                                        }
                                                        required
                                                    >
                                                        <option value="">
                                                            Select
                                                        </option>
                                                        {formOptions.stations.map(
                                                            (station) => (
                                                                <option
                                                                    key={
                                                                        station.id
                                                                    }
                                                                    value={
                                                                        station.id
                                                                    }
                                                                >
                                                                    {
                                                                        station.name
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={
                                                            errors.to_station_id
                                                        }
                                                    />
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                </FormSection>
                            ) : null}

                            <FormPanelWithAction
                                title="Goods"
                                action={
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setGoods((current) => [
                                                ...current,
                                                emptyGoods(),
                                            ])
                                        }
                                    >
                                        Add row
                                    </Button>
                                }
                            >
                                <InputError message={errors.goods} />

                                <div className="space-y-3">
                                    {goods.map((row, index) => (
                                        <div
                                            key={index}
                                            className="space-y-3 rounded-lg border border-border/70 p-3"
                                        >
                                            <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Goods Type
                                                    </Label>
                                                    <select
                                                        name={`goods[${index}][goods_type_id]`}
                                                        className={fieldClass}
                                                        value={
                                                            row.goods_type_id
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    goods_type_id:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'other',
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            Select
                                                        </option>
                                                        {formOptions.goodsTypes.map(
                                                            (item) => (
                                                                <option
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    value={
                                                                        item.id
                                                                    }
                                                                >
                                                                    {item.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Package Type
                                                    </Label>
                                                    <select
                                                        name={`goods[${index}][package_type_id]`}
                                                        className={fieldClass}
                                                        value={
                                                            row.package_type_id
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    package_type_id:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'other',
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            Select
                                                        </option>
                                                        {formOptions.packageTypes.map(
                                                            (item) => (
                                                                <option
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    value={
                                                                        item.id
                                                                    }
                                                                >
                                                                    {item.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        No. of Units
                                                    </Label>
                                                    <Input
                                                        name={`goods[${index}][package_count]`}
                                                        type="number"
                                                        min="0"
                                                        step="0.001"
                                                        className="h-9"
                                                        value={
                                                            row.package_count
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    package_count:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'package_count',
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Wt./Unit (kg)
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        step="0.001"
                                                        className="h-9"
                                                        value={
                                                            row.weight_per_unit
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    weight_per_unit:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'weight_per_unit',
                                                            )
                                                        }
                                                        aria-label={`Weight per unit row ${index + 1}`}
                                                    />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Actual Weight (kg)
                                                    </Label>
                                                    <Input
                                                        name={`goods[${index}][actual_weight]`}
                                                        type="number"
                                                        min="0"
                                                        step="0.001"
                                                        className="h-9"
                                                        value={
                                                            row.actual_weight
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    actual_weight:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'actual_weight',
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Charged Weight (kg)
                                                    </Label>
                                                    <Input
                                                        name={`goods[${index}][charged_weight]`}
                                                        type="number"
                                                        min="0"
                                                        step="0.001"
                                                        className="h-9"
                                                        value={
                                                            row.charged_weight
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    charged_weight:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'other',
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                                {mayViewFreight ? (
                                                    <>
                                                        <div className="space-y-1">
                                                            <Label className="text-xs">
                                                                Rate Type
                                                            </Label>
                                                            <select
                                                                name={`goods[${index}][freight_rate_type]`}
                                                                className={
                                                                    fieldClass
                                                                }
                                                                value={
                                                                    row.freight_rate_type
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            freight_rate_type:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'other',
                                                                    )
                                                                }
                                                                required
                                                            >
                                                                {formOptions.rateTypeOptions.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <option
                                                                            key={
                                                                                option.value
                                                                            }
                                                                            value={
                                                                                option.value
                                                                            }
                                                                        >
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label className="text-xs">
                                                                Rate
                                                            </Label>
                                                            <Input
                                                                name={`goods[${index}][freight_rate]`}
                                                                type="number"
                                                                min="0"
                                                                step="0.0001"
                                                                className="h-9"
                                                                value={
                                                                    row.freight_rate
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            freight_rate:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'other',
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label className="text-xs">
                                                                Freight Amount
                                                            </Label>
                                                            <span className="flex h-9 items-center text-muted-foreground tabular-nums">
                                                                {formatPreviewAmount(
                                                                    computeFreightPreview(
                                                                        row,
                                                                    ),
                                                                )}
                                                            </span>
                                                        </div>
                                                    </>
                                                ) : null}
                                                <div className="space-y-1">
                                                    <Label className="text-xs">
                                                        Goods Value
                                                    </Label>
                                                    <Input
                                                        name={`goods[${index}][goods_value]`}
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        className="h-9"
                                                        value={row.goods_value}
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    goods_value:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'other',
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-1 md:col-span-2 xl:col-span-1">
                                                    <Label className="text-xs">
                                                        Goods Description
                                                        <span className="font-normal text-muted-foreground">
                                                            {' '}
                                                            (optional)
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        name={`goods[${index}][goods_description]`}
                                                        className="h-9"
                                                        value={
                                                            row.goods_description
                                                        }
                                                        onChange={(e) =>
                                                            applyGoodsField(
                                                                index,
                                                                {
                                                                    goods_description:
                                                                        e.target
                                                                            .value,
                                                                },
                                                                'other',
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="flex items-end justify-end">
                                                    {goods.length > 1 ? (
                                                        <Button
                                                            type="button"
                                                            variant="destructive"
                                                            size="sm"
                                                            className="gap-1.5"
                                                            onClick={() =>
                                                                setGoods(
                                                                    (current) =>
                                                                        current.filter(
                                                                            (
                                                                                _,
                                                                                i,
                                                                            ) =>
                                                                                i !==
                                                                                index,
                                                                        ),
                                                                )
                                                            }
                                                        >
                                                            <Trash2
                                                                className="size-3.5"
                                                                aria-hidden
                                                            />
                                                            Remove
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </FormPanelWithAction>

                            <FormPanelWithAction
                                title="E-way bills"
                                action={
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setEwbs((current) => [
                                                ...current,
                                                {
                                                    ewb_number: '',
                                                    ewb_date: '',
                                                    valid_until: '',
                                                },
                                            ])
                                        }
                                    >
                                        Add EWB
                                    </Button>
                                }
                            >
                                {ewbs.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No e-way bills added yet.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[520px] text-left text-sm">
                                            <thead className="text-muted-foreground">
                                                <tr className="border-b border-border">
                                                    <th className="px-2 py-2 font-medium">
                                                        Number
                                                    </th>
                                                    <th className="px-2 py-2 font-medium">
                                                        Date
                                                    </th>
                                                    <th className="px-2 py-2 font-medium">
                                                        Valid until
                                                    </th>
                                                    <th className="px-2 py-2 font-medium">
                                                        Remove
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {ewbs.map((row, index) => (
                                                    <tr
                                                        key={index}
                                                        className="border-b border-border/70"
                                                    >
                                                        <td className="px-2 py-2">
                                                            <Input
                                                                name={`eway_bills[${index}][ewb_number]`}
                                                                className="h-9"
                                                                value={
                                                                    row.ewb_number
                                                                }
                                                                onChange={(e) =>
                                                                    setEwbs(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.map(
                                                                                (
                                                                                    item,
                                                                                    i,
                                                                                ) =>
                                                                                    i ===
                                                                                    index
                                                                                        ? {
                                                                                              ...item,
                                                                                              ewb_number:
                                                                                                  e
                                                                                                      .target
                                                                                                      .value,
                                                                                          }
                                                                                        : item,
                                                                            ),
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            <Input
                                                                name={`eway_bills[${index}][ewb_date]`}
                                                                type="date"
                                                                className={
                                                                    dateFieldClass
                                                                }
                                                                value={
                                                                    row.ewb_date
                                                                }
                                                                onChange={(e) =>
                                                                    setEwbs(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.map(
                                                                                (
                                                                                    item,
                                                                                    i,
                                                                                ) =>
                                                                                    i ===
                                                                                    index
                                                                                        ? {
                                                                                              ...item,
                                                                                              ewb_date:
                                                                                                  e
                                                                                                      .target
                                                                                                      .value,
                                                                                          }
                                                                                        : item,
                                                                            ),
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            <Input
                                                                name={`eway_bills[${index}][valid_until]`}
                                                                type="date"
                                                                className={
                                                                    dateFieldClass
                                                                }
                                                                value={
                                                                    row.valid_until
                                                                }
                                                                onChange={(e) =>
                                                                    setEwbs(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.map(
                                                                                (
                                                                                    item,
                                                                                    i,
                                                                                ) =>
                                                                                    i ===
                                                                                    index
                                                                                        ? {
                                                                                              ...item,
                                                                                              valid_until:
                                                                                                  e
                                                                                                      .target
                                                                                                      .value,
                                                                                          }
                                                                                        : item,
                                                                            ),
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            <Button
                                                                type="button"
                                                                variant="destructive"
                                                                size="sm"
                                                                className="gap-1.5"
                                                                onClick={() =>
                                                                    setEwbs(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.filter(
                                                                                (
                                                                                    _,
                                                                                    i,
                                                                                ) =>
                                                                                    i !==
                                                                                    index,
                                                                            ),
                                                                    )
                                                                }
                                                            >
                                                                <Trash2
                                                                    className="size-3.5"
                                                                    aria-hidden
                                                                />
                                                                Remove
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </FormPanelWithAction>

                            <FormPanelWithAction
                                title="Additional charges"
                                action={
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            setCharges((current) => [
                                                ...current,
                                                {
                                                    description: '',
                                                    amount: '',
                                                },
                                            ])
                                        }
                                    >
                                        Add charge
                                    </Button>
                                }
                            >
                                {charges.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No additional charges added yet.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[420px] text-left text-sm">
                                            <thead className="text-muted-foreground">
                                                <tr className="border-b border-border">
                                                    <th className="px-2 py-2 font-medium">
                                                        Description
                                                    </th>
                                                    <th className="px-2 py-2 font-medium">
                                                        Amount
                                                    </th>
                                                    <th className="px-2 py-2 font-medium">
                                                        Remove
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {charges.map((row, index) => (
                                                    <tr
                                                        key={index}
                                                        className="border-b border-border/70"
                                                    >
                                                        <td className="px-2 py-2">
                                                            <Input
                                                                name={`additional_charges[${index}][description]`}
                                                                className="h-9"
                                                                value={
                                                                    row.description
                                                                }
                                                                onChange={(e) =>
                                                                    setCharges(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.map(
                                                                                (
                                                                                    item,
                                                                                    i,
                                                                                ) =>
                                                                                    i ===
                                                                                    index
                                                                                        ? {
                                                                                              ...item,
                                                                                              description:
                                                                                                  e
                                                                                                      .target
                                                                                                      .value,
                                                                                          }
                                                                                        : item,
                                                                            ),
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            <Input
                                                                name={`additional_charges[${index}][amount]`}
                                                                type="number"
                                                                min="0.01"
                                                                step="0.01"
                                                                className="h-9"
                                                                value={
                                                                    row.amount
                                                                }
                                                                onChange={(e) =>
                                                                    setCharges(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.map(
                                                                                (
                                                                                    item,
                                                                                    i,
                                                                                ) =>
                                                                                    i ===
                                                                                    index
                                                                                        ? {
                                                                                              ...item,
                                                                                              amount: e
                                                                                                  .target
                                                                                                  .value,
                                                                                          }
                                                                                        : item,
                                                                            ),
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-2 py-2">
                                                            <Button
                                                                type="button"
                                                                variant="destructive"
                                                                size="sm"
                                                                className="gap-1.5"
                                                                onClick={() =>
                                                                    setCharges(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.filter(
                                                                                (
                                                                                    _,
                                                                                    i,
                                                                                ) =>
                                                                                    i !==
                                                                                    index,
                                                                            ),
                                                                    )
                                                                }
                                                            >
                                                                <Trash2
                                                                    className="size-3.5"
                                                                    aria-hidden
                                                                />
                                                                Remove
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </FormPanelWithAction>

                            <section
                                className={`${sectionShellClass} px-4 py-3`}
                            >
                                <h2 className="mb-3 text-sm font-semibold tracking-tight">
                                    Summary
                                </h2>
                                <dl className="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    <div className="flex justify-between gap-3 sm:block">
                                        <dt className="text-muted-foreground">
                                            Total Units
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {summary.totalUnits}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3 sm:block">
                                        <dt className="text-muted-foreground">
                                            Actual Wt
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {summary.totalActual}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3 sm:block">
                                        <dt className="text-muted-foreground">
                                            Charged Wt
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {summary.totalCharged}
                                        </dd>
                                    </div>
                                    {mayViewFreight ? (
                                        <>
                                            <div className="flex justify-between gap-3 sm:block">
                                                <dt className="text-muted-foreground">
                                                    Freight
                                                </dt>
                                                <dd className="font-medium tabular-nums">
                                                    {summary.totalFreight}
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-3 sm:block">
                                                <dt className="text-muted-foreground">
                                                    Additional Charges
                                                </dt>
                                                <dd className="font-medium tabular-nums">
                                                    {summary.totalAdditional}
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-3 sm:block">
                                                <dt className="text-muted-foreground">
                                                    Total Amount
                                                </dt>
                                                <dd className="font-medium tabular-nums">
                                                    {summary.totalAmount}
                                                </dd>
                                            </div>
                                        </>
                                    ) : null}
                                    <div className="flex justify-between gap-3 sm:block">
                                        <dt className="text-muted-foreground">
                                            Goods Value
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {summary.totalGoodsValue}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <div className="flex items-center justify-end gap-3">
                                <Button variant="outline" asChild>
                                    <Link href={urls.show ?? urls.index}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    Save Bilty
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PageShell>
        </>
    );
}
