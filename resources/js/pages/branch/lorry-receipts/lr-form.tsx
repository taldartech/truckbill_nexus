import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import { normalizeComparable } from '@/components/form-confirmation/diff';
import type {
    ChangeReview,
    FieldReview,
    FieldReviewSection,
} from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import {
    DEFAULT_FREIGHT_RATE_TYPE,
    FREIGHT_RATE_TYPE_OPTIONS,
    freightRateTypeLabel,
} from '@/lib/freight-rate-type-options';
import {
    QuickCreateCatalogueSheet,
    type QuickCreatedCatalogueItem,
} from '@/components/masters/quick-create-catalogue-sheet';
import {
    QuickCreatePartySheet,
    type QuickCreatePartyRole,
} from '@/components/masters/quick-create-party-sheet';
import {
    QuickCreateStationSheet,
    type QuickCreatedStation,
} from '@/components/masters/quick-create-station-sheet';
import {
    SearchablePartySelect,
    type PartyOption,
} from '@/components/searchable-party-select';
import {
    SearchableVehicleSelect,
    type VehicleSelectOption,
} from '@/components/searchable-vehicle-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    actualMtFromUnitsAndWpuMt,
    formatFixed,
    formatPackageCount,
    kgToMt,
    mtToKg,
    normalizeWpuMtFromFormState,
    parseNumeric,
    wpuMtFromActualMt,
} from '@/lib/operational-weight';
import {
    LrfActions,
    LrfDriverTdsSummary,
    LrfInnerPanel,
    LrfPage,
    LrfPartyCard,
    LrfPartyMetaGrid,
    LrfPayPills,
    LrfReceiptCard,
    LrfRouteConnector,
    LrfSection,
    LrfSummaryMetrics,
} from '@/pages/branch/lorry-receipts/lr-form-sections';

type Option = { value: string; label: string };
type NamedOption = {
    id: string;
    name: string;
    city?: string | null;
    state?: string | null;
};
type VehicleOption = VehicleSelectOption;

type GoodsRow = {
    id?: string;
    goods_type_id: string;
    package_type_id: string;
    goods_description: string;
    package_count: string;
    weight_per_unit: string;
    actual_weight: string;
    charged_weight: string;
    freight_rate_type: string;
    rate_semantics: string;
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
    external_gr_number?: string | null;
    client_invoice_number?: string | null;
    reference_by?: string | null;
    goods: Array<{
        id?: string | null;
        goods_type_id: string | null;
        package_type_id: string | null;
        goods_description: string | null;
        package_count: string | null;
        actual_weight: string | null;
        charged_weight: string | null;
        freight_rate_type: string;
        rate_semantics?: string | null;
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
        consignors: PartyOption[];
        consignees: PartyOption[];
        billingCustomers?: PartyOption[];
        stations: NamedOption[];
        goodsTypes: NamedOption[];
        packageTypes: NamedOption[];
        vehicles?: VehicleOption[];
        drivers?: PartyOption[];
        vehicleQuickCreate?: {
            ownershipOptions?: Option[];
            truckTypesAvailable?: boolean;
            truckTypes: NamedOption[];
            tyreLayouts: NamedOption[];
            drivers: NamedOption[];
            truckTypesStoreUrl?: string | null;
            tyreLayoutsStoreUrl?: string | null;
        };
    };
    urls: {
        index: string;
        store?: string;
        update?: string;
        show?: string;
        parties_options?: string;
        parties_tds_for_payee?: string;
        parties_create?: string;
        parties_store?: string;
        vehicles_options?: string;
        vehicles_create?: string;
        vehicles_store?: string;
        goods_types_store?: string;
        package_types_store?: string;
        stations_store?: string;
    };
    permissions?: {
        can_view_freight?: boolean;
        can_create_lorry_hire?: boolean;
        can_create_trip?: boolean;
        can_quick_create_catalogues?: boolean;
    };
};

type GoodsFieldSource =
    | 'weight_per_unit'
    | 'actual_weight'
    | 'package_count'
    | 'charged_weight'
    | 'match_actual'
    | 'other';

const NEW_ROW_RATE_TYPE_VALUES = new Set<string>(
    FREIGHT_RATE_TYPE_OPTIONS.map((option) => option.value),
);

const fieldClass =
    'flex h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';
const dateFieldClass = `${fieldClass} min-w-[9.5rem]`;

type IntendedPayeeType = 'owner' | 'agency' | '';

function todayIsoLocal(): string {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function computeFreightPreview(row: GoodsRow): number | null {
    const rate = parseNumeric(row.freight_rate);
    if (rate === null) {
        return null;
    }

    const units = parseNumeric(row.package_count);
    // UI stores actual/charged weight in MT; freight math uses KG for per-kg types.
    const actualMt = parseNumeric(row.actual_weight);
    const chargedMt = parseNumeric(row.charged_weight);
    const actualKg = actualMt === null ? null : actualMt * 1000;

    switch (row.freight_rate_type) {
        case 'fixed_amount':
            return rate;
        case 'per_package_unit':
            if (units === null) {
                return null;
            }
            return rate * units;
        case 'per_kg':
            if (actualKg === null) {
                return null;
            }
            return rate * actualKg;
        case 'per_actual_weight':
            if (actualMt === null) {
                return null;
            }
            return rate * actualMt;
        case 'per_mt':
            if (actualMt === null) {
                return null;
            }
            return rate * actualMt;
        case 'per_charged_weight':
            if (chargedMt === null) {
                return null;
            }
            return rate * chargedMt;
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
    freight_rate_type: DEFAULT_FREIGHT_RATE_TYPE,
    rate_semantics: 'weight_mt',
    freight_rate: '0',
    goods_value: '',
});

function computeLrSummary(goodsRows: GoodsRow[], chargeRows: ChargeRow[]) {
    const totalActual = sumNumericStrings(
        goodsRows.map((row) => row.actual_weight),
    );
    const totalCharged = sumNumericStrings(
        goodsRows.map((row) => row.charged_weight),
    );
    const totalFreight = goodsRows.reduce((total, row) => {
        const preview = computeFreightPreview(row);
        return preview === null ? total : total + preview;
    }, 0);
    const totalAdditional = sumNumericStrings(
        chargeRows.map((row) => row.amount),
    );
    return {
        totalActual: formatFixed(totalActual, 3),
        totalCharged: formatFixed(totalCharged, 3),
        totalFreight: formatFixed(totalFreight, 2),
        totalAdditional: formatFixed(totalAdditional, 2),
        totalAmount: formatFixed(totalFreight + totalAdditional, 2),
    };
}

function formatGoodsRowReview(
    row: GoodsRow,
    index: number,
    goodsTypes: NamedOption[],
    packageTypes: NamedOption[],
    mayViewFreight: boolean,
): string {
    const goodsLabel =
        goodsTypes.find((option) => option.id === row.goods_type_id)?.name ??
        `Goods ${index + 1}`;
    const packageLabel =
        packageTypes.find((option) => option.id === row.package_type_id)
            ?.name ?? '—';
    const freightPreview = computeFreightPreview(row);
    return [
        goodsLabel,
        `Pkg ${packageLabel}`,
        `Units ${row.package_count || '—'}`,
        `Wt/Unit ${row.weight_per_unit || '—'} MT`,
        `Actual ${row.actual_weight || '—'} MT`,
        `Charged ${row.charged_weight || '—'} MT`,
        mayViewFreight
            ? `Rate ${row.freight_rate || '—'} (${freightRateTypeLabel(row.freight_rate_type || '')})`
            : null,
        mayViewFreight
            ? `Freight ${freightPreview !== null ? formatFixed(freightPreview, 2) : '—'}`
            : null,
        row.goods_value ? `Value ${row.goods_value}` : null,
        row.goods_description ? `Desc ${row.goods_description}` : null,
    ]
        .filter((part): part is string => part !== null)
        .join(' · ');
}

function formatEwbReview(ewbRows: EwbRow[]): string {
    if (ewbRows.length === 0) {
        return '—';
    }
    return ewbRows.map((row) => row.ewb_number.trim() || '—').join(', ');
}

function formatChargesReview(chargeRows: ChargeRow[]): string {
    if (chargeRows.length === 0) {
        return '—';
    }
    return chargeRows
        .map(
            (row) =>
                `${row.description.trim() || 'Charge'}: ${row.amount || '—'}`,
        )
        .join('; ');
}

function pushScalarChange(
    changes: ChangeReview[],
    label: string,
    previousRaw: string,
    nextRaw: string,
    group?: string,
): void {
    const previous = normalizeComparable(previousRaw);
    const next = normalizeComparable(nextRaw);
    if (previous === next) {
        return;
    }
    if (previous === '' && next !== '') {
        changes.push({
            label,
            previous: '—',
            next,
            changeType: 'added',
            group,
        });
        return;
    }
    if (previous !== '' && next === '') {
        changes.push({
            label,
            previous,
            next: '—',
            changeType: 'removed',
            group,
        });
        return;
    }
    changes.push({
        label,
        previous: previous || '—',
        next: next || '—',
        changeType: 'modified',
        group,
    });
}

function partyLocationText(party: PartyOption | null | undefined): string {
    if (!party?.city) {
        return '';
    }
    const city = party.city.trim();
    if (city === '') {
        return '';
    }
    const state = party.state?.trim();
    return state ? `${city}, ${state}` : city;
}

function rateTypeOptionsForRow(
    row: GoodsRow,
    allOptions: Option[],
    isNewRow: boolean,
): Option[] {
    if (isNewRow) {
        return allOptions.filter((option) =>
            NEW_ROW_RATE_TYPE_VALUES.has(option.value),
        );
    }
    const filtered = allOptions.filter(
        (option) =>
            NEW_ROW_RATE_TYPE_VALUES.has(option.value) ||
            option.value === row.freight_rate_type,
    );
    if (
        row.freight_rate_type &&
        !filtered.some((option) => option.value === row.freight_rate_type)
    ) {
        return [
            ...filtered,
            {
                value: row.freight_rate_type,
                label: row.freight_rate_type,
            },
        ];
    }
    return filtered.length > 0 ? filtered : allOptions;
}

function resolveLocationDisplay(
    text: string | null | undefined,
    stationId: string | null | undefined,
    stations: NamedOption[],
): string {
    const trimmedText = text?.trim() ?? '';
    if (trimmedText !== '') {
        return trimmedText;
    }
    const stationName = stations.find(
        (station) => station.id === stationId,
    )?.name;
    return stationName ?? '—';
}

function mapGoodsFromReceipt(goods: LorryReceiptDetail['goods']): GoodsRow[] {
    if (goods.length === 0) {
        return [emptyGoods()];
    }
    return goods.map((row) => {
        const packageCount = row.package_count ?? '';
        const actualMt = kgToMt(row.actual_weight);
        const chargedMt = kgToMt(row.charged_weight);
        return {
            id: row.id ?? undefined,
            goods_type_id: row.goods_type_id ?? '',
            package_type_id: row.package_type_id ?? '',
            goods_description: row.goods_description ?? '',
            package_count: formatPackageCount(packageCount),
            weight_per_unit: normalizeWpuMtFromFormState(
                wpuMtFromActualMt(actualMt, packageCount),
                actualMt,
                packageCount,
            ),
            actual_weight: actualMt,
            charged_weight: chargedMt,
            freight_rate_type: row.freight_rate_type,
            rate_semantics: row.rate_semantics ?? 'weight_kg',
            freight_rate: row.freight_rate ?? '0',
            goods_value: row.goods_value ?? '',
        };
    });
}

function findPartyOption(
    parties: PartyOption[],
    id: string | null | undefined,
): PartyOption | null {
    if (!id) {
        return null;
    }
    return parties.find((party) => party.id === id) ?? null;
}

function FormProcessingBridge({
    processing,
    onChange,
}: {
    processing: boolean;
    onChange: (value: boolean) => void;
}) {
    // Only notify on actual processing transitions — avoid re-firing when the
    // parent passes a new onChange identity while processing is unchanged.
    const previous = useRef<boolean | null>(null);
    useEffect(() => {
        if (previous.current === processing) {
            return;
        }
        previous.current = processing;
        onChange(processing);
    }, [onChange, processing]);
    return null;
}

export default function LrForm({
    mode,
    formMode: formModeProp,
    workflowContext,
    contextLabel: _contextLabel,
    lorryReceipt,
    formOptions,
    urls,
    permissions,
}: Props) {
    const mayViewFreight = permissions?.can_view_freight !== false;
    const canCreateLorryHire = permissions?.can_create_lorry_hire === true;
    const canCreateTrip = permissions?.can_create_trip === true;
    const canQuickCreateCatalogues =
        permissions?.can_quick_create_catalogues === true;
    const formMode =
        formModeProp ?? (workflowContext === 'partload' ? 'partload' : 'truck');
    const submitAllowed = useRef(false);
    const redirectToHire = useRef(false);
    const redirectToTrip = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [formProcessing, setFormProcessing] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
    const [reviewSections, setReviewSections] = useState<FieldReviewSection[]>(
        [],
    );
    const [reviewChanges, setReviewChanges] = useState<ChangeReview[]>([]);
    const initialGoodsRowCount = useRef(
        lorryReceipt?.goods?.length ? lorryReceipt.goods.length : 1,
    );

    const [vehicleId, setVehicleId] = useState(
        (lorryReceipt as { vehicle_id?: string | null } | null)?.vehicle_id ??
            '',
    );
    const [selectedVehicleMeta, setSelectedVehicleMeta] =
        useState<VehicleSelectOption | null>(() => {
            const initialId =
                (lorryReceipt as { vehicle_id?: string | null } | null)
                    ?.vehicle_id ?? '';
            return (
                (formOptions.vehicles ?? []).find(
                    (vehicle) => vehicle.id === initialId,
                ) ?? null
            );
        });
    const [billingCustomerId, setBillingCustomerId] = useState(
        (lorryReceipt as { billing_customer_party_id?: string | null } | null)
            ?.billing_customer_party_id ?? '',
    );
    const [consignorPartyId, setConsignorPartyId] = useState(
        lorryReceipt?.consignor_party_id ?? '',
    );
    const [consigneePartyId, setConsigneePartyId] = useState(
        lorryReceipt?.consignee_party_id ?? '',
    );
    const [consignorParty, setConsignorParty] = useState<PartyOption | null>(
        () =>
            findPartyOption(
                formOptions.consignors,
                lorryReceipt?.consignor_party_id,
            ),
    );
    const [consigneeParty, setConsigneeParty] = useState<PartyOption | null>(
        () =>
            findPartyOption(
                formOptions.consignees,
                lorryReceipt?.consignee_party_id,
            ),
    );
    const [intendedPayeeType, setIntendedPayeeType] =
        useState<IntendedPayeeType>(() => {
            const stored = (
                lorryReceipt as { intended_payee_type?: string | null } | null
            )?.intended_payee_type;
            if (stored === 'owner' || stored === 'agency') {
                return stored;
            }
            return '';
        });
    const [intendedPayeePartyId, setIntendedPayeePartyId] = useState(
        (lorryReceipt as { intended_payee_party_id?: string | null } | null)
            ?.intended_payee_party_id ?? '',
    );
    const [useSubstituteDriver, setUseSubstituteDriver] = useState(false);
    const [substituteDriverPartyId, setSubstituteDriverPartyId] = useState('');
    const [agencyPayeeParty, setAgencyPayeeParty] =
        useState<PartyOption | null>(null);
    const [headerQuickCreate, setHeaderQuickCreate] =
        useState<QuickCreatePartyRole | null>(null);
    const [goodsTypes, setGoodsTypes] = useState<NamedOption[]>(
        () => formOptions.goodsTypes,
    );
    const [packageTypes, setPackageTypes] = useState<NamedOption[]>(
        () => formOptions.packageTypes,
    );
    const [catalogueQuickCreate, setCatalogueQuickCreate] = useState<{
        kind: 'goods' | 'package';
        rowIndex: number;
    } | null>(null);
    const [stations, setStations] = useState<NamedOption[]>(
        () => formOptions.stations,
    );
    const [fromStationId, setFromStationId] = useState(
        lorryReceipt?.from_station_id ?? '',
    );
    const [toStationId, setToStationId] = useState(
        lorryReceipt?.to_station_id ?? '',
    );
    const [stationQuickCreateTarget, setStationQuickCreateTarget] = useState<
        'from' | 'to' | null
    >(null);
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
    const [externalGrNumber, setExternalGrNumber] = useState(
        lorryReceipt?.external_gr_number ?? '',
    );
    const [clientInvoiceNumber, setClientInvoiceNumber] = useState(
        lorryReceipt?.client_invoice_number ?? '',
    );
    const [referenceByPartyId, setReferenceByPartyId] = useState(
        (lorryReceipt as { reference_by_party_id?: string | null } | null)
            ?.reference_by_party_id ?? '',
    );
    const [referenceBy, setReferenceBy] = useState(
        lorryReceipt?.reference_by ?? '',
    );
    const [declaredGoodsValue, setDeclaredGoodsValue] = useState(
        lorryReceipt?.goods_value ?? '',
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
    const chargedWeightTouched = useRef<Record<number, boolean>>(
        (() => {
            const initial: Record<number, boolean> = {};
            if (lorryReceipt?.goods?.length) {
                mapGoodsFromReceipt(lorryReceipt.goods).forEach(
                    (row, index) => {
                        if (
                            row.charged_weight !== '' &&
                            row.actual_weight !== '' &&
                            row.charged_weight !== row.actual_weight
                        ) {
                            initial[index] = true;
                        }
                    },
                );
            }
            return initial;
        })(),
    );
    const [fromText, setFromText] = useState(lorryReceipt?.from_text ?? '');
    const [toText, setToText] = useState(lorryReceipt?.to_text ?? '');

    const selectedVehicle =
        selectedVehicleMeta ??
        (formOptions.vehicles ?? []).find(
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
            ? 'Add New Good Receipt (Bilty)'
            : `Edit Good Receipt (Bilty) ${lorryReceipt?.lr_number ?? ''}`;
    const subtitle =
        'Complete steps 1–3 to save. Optional details can be added anytime.';
    const isMarketVehicle =
        showVehicle &&
        Boolean(vehicleId) &&
        selectedVehicle?.ownership === 'market';
    const isOwnVehicle =
        showVehicle &&
        Boolean(vehicleId) &&
        selectedVehicle?.ownership === 'own';
    const showPayTo = isMarketVehicle;
    const marketVehicleHasOwner = Boolean(selectedVehicle?.owner_party_id);
    const showPayeeRow =
        showPayTo &&
        (intendedPayeeType === 'owner' || intendedPayeeType === 'agency');
    const [tdsPreviewLabel, setTdsPreviewLabel] = useState('—');
    const routeFromLabel =
        partyLocationText(consignorParty) || fromText.trim() || '—';
    const routeToLabel =
        partyLocationText(consigneeParty) || toText.trim() || '—';
    const routeFlowHint =
        routeFromLabel !== '—' && routeToLabel !== '—'
            ? `${routeFromLabel} → ${routeToLabel}`
            : 'Goods move from consignor → consignee';

    useEffect(() => {
        if (!needsTextLocations) {
            return;
        }
        const next = partyLocationText(consignorParty);
        if (next !== '') {
            setFromText(next);
        }
    }, [consignorParty, needsTextLocations]);

    useEffect(() => {
        if (!needsTextLocations) {
            return;
        }
        const next = partyLocationText(consigneeParty);
        if (next !== '') {
            setToText(next);
        }
    }, [consigneeParty, needsTextLocations]);

    useEffect(() => {
        if (!isMarketVehicle) {
            setIntendedPayeeType('');
            setIntendedPayeePartyId('');
            setAgencyPayeeParty(null);
            setTdsPreviewLabel('—');
        }
    }, [isMarketVehicle]);

    useEffect(() => {
        if (
            !isMarketVehicle ||
            marketVehicleHasOwner ||
            intendedPayeeType === 'agency'
        ) {
            return;
        }
        if (intendedPayeeType === 'owner') {
            setIntendedPayeePartyId('');
        }
        setIntendedPayeeType('agency');
    }, [intendedPayeeType, isMarketVehicle, marketVehicleHasOwner]);

    useEffect(() => {
        if (!isOwnVehicle) {
            setUseSubstituteDriver(false);
            setSubstituteDriverPartyId('');
        }
    }, [isOwnVehicle]);

    const allowedOwnershipValues = useMemo(
        () =>
            (formOptions.vehicleQuickCreate?.ownershipOptions ?? []).map(
                (item) => item.value,
            ),
        [formOptions.vehicleQuickCreate?.ownershipOptions],
    );
    const allowOwnQuickCreate =
        allowedOwnershipValues.length === 0 ||
        allowedOwnershipValues.includes('own');
    const allowMarketQuickCreate =
        allowedOwnershipValues.length === 0 ||
        allowedOwnershipValues.includes('market');
    const defaultQuickVehicleOwnership: 'market' | 'own' =
        allowMarketQuickCreate ? 'market' : 'own';
    const normalizedVehicleCatalogues = useMemo(
        () =>
            formOptions.vehicleQuickCreate
                ? {
                      truckTypesAvailable:
                          formOptions.vehicleQuickCreate.truckTypesAvailable,
                      truckTypes: formOptions.vehicleQuickCreate.truckTypes,
                      tyreLayouts: formOptions.vehicleQuickCreate.tyreLayouts,
                      drivers: formOptions.vehicleQuickCreate.drivers,
                      truckTypesStoreUrl:
                          formOptions.vehicleQuickCreate.truckTypesStoreUrl ??
                          undefined,
                      tyreLayoutsStoreUrl:
                          formOptions.vehicleQuickCreate.tyreLayoutsStoreUrl ??
                          undefined,
                  }
                : undefined,
        [formOptions.vehicleQuickCreate],
    );

    useEffect(() => {
        if (intendedPayeeType !== 'owner') {
            return;
        }
        if (selectedVehicle?.owner_party_id) {
            setIntendedPayeePartyId(selectedVehicle.owner_party_id);
        } else {
            setIntendedPayeePartyId('');
        }
    }, [intendedPayeeType, selectedVehicle?.owner_party_id]);

    useEffect(() => {
        const payeeId =
            intendedPayeeType === 'owner'
                ? (selectedVehicle?.owner_party_id ?? intendedPayeePartyId)
                : intendedPayeePartyId;
        const endpoint = urls.parties_tds_for_payee;
        if (!payeeId || !endpoint || !showPayeeRow) {
            setTdsPreviewLabel('—');
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams({ party_id: payeeId });
            void fetch(`${endpoint}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('TDS preview failed');
                    }
                    const payload = (await response.json()) as {
                        applicable_percent?: string | null;
                    };
                    const percent = payload.applicable_percent?.trim();
                    setTdsPreviewLabel(
                        percent && percent !== '' ? `${percent}%` : '—',
                    );
                })
                .catch((error: unknown) => {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }
                    setTdsPreviewLabel('—');
                });
        }, 200);
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [
        intendedPayeePartyId,
        intendedPayeeType,
        selectedVehicle?.owner_party_id,
        showPayeeRow,
        urls.parties_tds_for_payee,
    ]);

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

                    if (source === 'package_count') {
                        next.package_count = formatPackageCount(
                            next.package_count,
                        );
                    }

                    if (source === 'weight_per_unit') {
                        next.actual_weight = actualMtFromUnitsAndWpuMt(
                            next.package_count,
                            next.weight_per_unit,
                        );
                    } else if (source === 'package_count') {
                        if (next.weight_per_unit !== '') {
                            next.actual_weight = actualMtFromUnitsAndWpuMt(
                                next.package_count,
                                next.weight_per_unit,
                            );
                        } else {
                            next.weight_per_unit = wpuMtFromActualMt(
                                next.actual_weight,
                                next.package_count,
                            );
                        }
                    } else if (source === 'actual_weight') {
                        next.weight_per_unit = wpuMtFromActualMt(
                            next.actual_weight,
                            next.package_count,
                        );
                    } else if (source === 'charged_weight') {
                        chargedWeightTouched.current[index] = true;
                    } else if (source === 'match_actual') {
                        chargedWeightTouched.current[index] = false;
                        next.charged_weight = next.actual_weight;
                    }

                    if (
                        source !== 'charged_weight' &&
                        source !== 'match_actual' &&
                        !chargedWeightTouched.current[index] &&
                        next.actual_weight !== ''
                    ) {
                        if (
                            source === 'actual_weight' ||
                            source === 'weight_per_unit' ||
                            source === 'package_count'
                        ) {
                            next.charged_weight = next.actual_weight;
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
        const totalGoodsValue = sumNumericStrings(
            goods.map((row) => row.goods_value),
        );
        const freightTotals = computeLrSummary(goods, charges);

        return {
            totalUnits: formatPackageCount(String(totalUnits)),
            totalGoodsValue: formatFixed(totalGoodsValue, 2),
            ...freightTotals,
        };
    }, [charges, goods]);

    const openConfirmation = (form: HTMLFormElement) => {
        const data = new FormData(form);
        const selectedOptionLabel = (fieldId: string): string => {
            const element = form.querySelector(
                `#${fieldId}`,
            ) as HTMLSelectElement | null;
            const text = element?.selectedOptions?.[0]?.text?.trim() ?? '';
            if (text === '' || text.toLowerCase().startsWith('select')) {
                return '—';
            }
            return text;
        };
        const formText = (name: string): string => {
            const value = data.get(name);
            return typeof value === 'string' && value.trim() !== ''
                ? value.trim()
                : '—';
        };

        const customerName =
            formOptions.billingCustomers?.find(
                (option) => option.id === billingCustomerId,
            )?.name ?? selectedOptionLabel('billing_customer_party_id');
        const vehicleLabel =
            selectedVehicle?.registration_number ??
            selectedOptionLabel('vehicle_id');
        const consignorName =
            consignorParty?.name ?? selectedOptionLabel('consignor_party_id');
        const consigneeName =
            consigneeParty?.name ?? selectedOptionLabel('consignee_party_id');
        const payeeTypeLabel =
            intendedPayeeType === 'owner'
                ? 'Truck Owner'
                : intendedPayeeType === 'agency'
                  ? 'Agency'
                  : '—';
        const payeePartyLabel =
            intendedPayeeType === 'owner'
                ? (selectedVehicle?.owner_name ??
                  (intendedPayeePartyId !== '' ? intendedPayeePartyId : '—'))
                : intendedPayeeType === 'agency'
                  ? (agencyPayeeParty?.name ?? '—')
                  : '—';
        const loadingStation =
            formText('from_text') !== '—'
                ? formText('from_text')
                : selectedOptionLabel('from_station_id');
        const unloadingStation =
            formText('to_text') !== '—'
                ? formText('to_text')
                : selectedOptionLabel('to_station_id');

        if (mode === 'edit' && lorryReceipt) {
            const changes: ChangeReview[] = [];
            const originalVehicleId =
                (lorryReceipt as { vehicle_id?: string | null }).vehicle_id ??
                '';
            const originalVehicleLabel =
                formOptions.vehicles?.find(
                    (option) => option.id === originalVehicleId,
                )?.label ??
                formOptions.vehicles?.find(
                    (option) => option.id === originalVehicleId,
                )?.registration_number ??
                '—';
            pushScalarChange(
                changes,
                'Vehicle',
                originalVehicleLabel,
                vehicleLabel,
                'Bilty Information',
            );
            const originalPayeeType =
                (
                    lorryReceipt as {
                        intended_payee_type?: string | null;
                    }
                ).intended_payee_type ?? '';
            const originalPayeeTypeLabel =
                originalPayeeType === 'owner'
                    ? 'Truck Owner'
                    : originalPayeeType === 'agency'
                      ? 'Agency'
                      : '—';
            pushScalarChange(
                changes,
                'Pay To',
                originalPayeeTypeLabel,
                payeeTypeLabel,
                'Market Vehicle Information',
            );
            pushScalarChange(
                changes,
                'Intended Payee',
                (
                    lorryReceipt as {
                        intended_payee_party_id?: string | null;
                    }
                ).intended_payee_party_id ?? '—',
                payeePartyLabel,
                'Market Vehicle Information',
            );
            const originalBillingId =
                (
                    lorryReceipt as {
                        billing_customer_party_id?: string | null;
                    }
                ).billing_customer_party_id ?? '';
            const originalBillingName =
                formOptions.billingCustomers?.find(
                    (option) => option.id === originalBillingId,
                )?.name ?? '—';
            pushScalarChange(
                changes,
                'Billing Customer',
                originalBillingName,
                customerName,
                'Bilty Information',
            );
            pushScalarChange(
                changes,
                'Consignor',
                formOptions.consignors.find(
                    (option) => option.id === lorryReceipt.consignor_party_id,
                )?.name ?? '—',
                consignorName,
                'Route Information',
            );
            pushScalarChange(
                changes,
                'Consignee',
                formOptions.consignees.find(
                    (option) => option.id === lorryReceipt.consignee_party_id,
                )?.name ?? '—',
                consigneeName,
                'Route Information',
            );
            pushScalarChange(
                changes,
                'Loading Station',
                resolveLocationDisplay(
                    lorryReceipt.from_text,
                    lorryReceipt.from_station_id,
                    stations,
                ),
                loadingStation,
                'Route Information',
            );
            pushScalarChange(
                changes,
                'Unloading Station',
                resolveLocationDisplay(
                    lorryReceipt.to_text,
                    lorryReceipt.to_station_id,
                    stations,
                ),
                unloadingStation,
                'Route Information',
            );

            const originalGoods = mapGoodsFromReceipt(lorryReceipt.goods);
            const goodsTypeOptions = goodsTypes;
            const packageTypeOptions = packageTypes;
            const maxGoodsRows = Math.max(originalGoods.length, goods.length);
            for (let index = 0; index < maxGoodsRows; index += 1) {
                const originalRow = originalGoods[index];
                const proposedRow = goods[index];
                const label = `Goods ${index + 1}`;
                if (originalRow && !proposedRow) {
                    changes.push({
                        label,
                        previous: formatGoodsRowReview(
                            originalRow,
                            index,
                            goodsTypeOptions,
                            packageTypeOptions,
                            mayViewFreight,
                        ),
                        next: '—',
                        changeType: 'removed',
                        group: 'Goods and Freight',
                    });
                    continue;
                }
                if (!originalRow && proposedRow) {
                    changes.push({
                        label,
                        previous: '—',
                        next: formatGoodsRowReview(
                            proposedRow,
                            index,
                            goodsTypeOptions,
                            packageTypeOptions,
                            mayViewFreight,
                        ),
                        changeType: 'added',
                        group: 'Goods and Freight',
                    });
                    continue;
                }
                if (originalRow && proposedRow) {
                    const previous = formatGoodsRowReview(
                        originalRow,
                        index,
                        goodsTypeOptions,
                        packageTypeOptions,
                        mayViewFreight,
                    );
                    const next = formatGoodsRowReview(
                        proposedRow,
                        index,
                        goodsTypeOptions,
                        packageTypeOptions,
                        mayViewFreight,
                    );
                    if (previous !== next) {
                        changes.push({
                            label,
                            previous,
                            next,
                            changeType: 'modified',
                            group: 'Goods and Freight',
                        });
                    }
                }
            }

            const originalEwbs: EwbRow[] = lorryReceipt.eway_bills.map(
                (row) => ({
                    ewb_number: row.ewb_number,
                    ewb_date: row.ewb_date ?? '',
                    valid_until: row.valid_until ?? '',
                }),
            );
            pushScalarChange(
                changes,
                'E-Way Bills',
                formatEwbReview(originalEwbs),
                formatEwbReview(ewbs),
                'Additional Information',
            );

            const originalCharges: ChargeRow[] =
                lorryReceipt.additional_charges.map((row) => ({
                    description: row.description,
                    amount: row.amount,
                }));
            pushScalarChange(
                changes,
                'Additional Charges',
                formatChargesReview(originalCharges),
                formatChargesReview(charges),
                'Additional Information',
            );

            const originalSummary = computeLrSummary(
                originalGoods,
                originalCharges,
            );
            pushScalarChange(
                changes,
                'Total Actual Weight (MT)',
                originalSummary.totalActual,
                summary.totalActual,
                'Goods and Freight',
            );
            pushScalarChange(
                changes,
                'Total Charged Weight (MT)',
                originalSummary.totalCharged,
                summary.totalCharged,
                'Goods and Freight',
            );
            pushScalarChange(
                changes,
                'Total Units',
                formatPackageCount(
                    String(
                        sumNumericStrings(
                            originalGoods.map((row) => row.package_count),
                        ),
                    ),
                ),
                summary.totalUnits,
                'Goods and Freight',
            );
            if (mayViewFreight) {
                pushScalarChange(
                    changes,
                    'Freight',
                    originalSummary.totalFreight,
                    summary.totalFreight,
                    'Goods and Freight',
                );
                pushScalarChange(
                    changes,
                    'Additional Charges',
                    originalSummary.totalAdditional,
                    summary.totalAdditional,
                    'Goods and Freight',
                );
                pushScalarChange(
                    changes,
                    'Final Amount',
                    originalSummary.totalAmount,
                    summary.totalAmount,
                    'Goods and Freight',
                );
            }

            setReviewChanges(changes);
            setReviewFields([]);
            setReviewSections([]);
            setConfirmOpen(true);
            return;
        }

        const lrNumber = data.get('lr_number');
        const lrDate = data.get('lr_date');
        const ownershipLabel =
            selectedVehicle?.ownership === 'own'
                ? 'Own Vehicle'
                : selectedVehicle?.ownership === 'market'
                  ? 'Market Vehicle'
                  : '—';
        const primaryDriverLabel =
            selectedVehicle?.primary_driver_name?.trim() || '—';
        const substituteDriverLabel =
            useSubstituteDriver && substituteDriverPartyId !== ''
                ? (formOptions.drivers?.find(
                      (option) => option.id === substituteDriverPartyId,
                  )?.name ?? substituteDriverPartyId)
                : null;

        const goodsTableHeaders = [
            'Goods Type',
            'Units',
            'Actual Wt (MT)',
            'Charged Wt (MT)',
            'Rate Type',
            ...(mayViewFreight ? ['Rate', 'Freight'] : []),
        ];
        const goodsTableRows = goods.map((row, index) => {
            const goodsLabel =
                goodsTypes.find((option) => option.id === row.goods_type_id)
                    ?.name ?? `Row ${index + 1}`;
            const freightPreview = computeFreightPreview(row);
            const base = [
                goodsLabel,
                row.package_count || '—',
                row.actual_weight || '—',
                row.charged_weight || '—',
                freightRateTypeLabel(row.freight_rate_type || ''),
            ];
            if (mayViewFreight) {
                base.push(
                    row.freight_rate || '—',
                    freightPreview !== null
                        ? formatFixed(freightPreview, 2)
                        : '—',
                );
            }
            return base;
        });

        const financialSummaryFields: FieldReview[] = [
            {
                label: 'Total Actual Weight (MT)',
                value: String(summary.totalActual),
            },
            {
                label: 'Total Charged Weight (MT)',
                value: String(summary.totalCharged),
            },
            {
                label: 'Total Units',
                value: String(summary.totalUnits),
            },
        ];
        if (mayViewFreight) {
            financialSummaryFields.push(
                {
                    label: 'Freight',
                    value: String(summary.totalFreight),
                },
                {
                    label: 'Additional Charges',
                    value: String(summary.totalAdditional),
                },
                {
                    label: 'Final Amount',
                    value: String(summary.totalAmount),
                    emphasis: 'total',
                },
            );
        }

        const additionalFields: FieldReview[] = [];
        if (externalGrNumber.trim() !== '') {
            additionalFields.push({
                label: 'GR Number',
                value: externalGrNumber.trim(),
            });
        }
        if (clientInvoiceNumber.trim() !== '') {
            additionalFields.push({
                label: 'Material Invoice Number',
                value: clientInvoiceNumber.trim(),
            });
        }
        if (ewbs.length > 0) {
            additionalFields.push({
                label: 'E-Way Bill',
                value: formatEwbReview(ewbs),
            });
        }
        if (charges.length > 0) {
            additionalFields.push({
                label: 'Additional Charge Lines',
                value: formatChargesReview(charges),
            });
        }
        const goodsValue = declaredGoodsValue.trim() || summary.totalGoodsValue;
        if (goodsValue !== '' && goodsValue !== '0' && goodsValue !== '0.00') {
            additionalFields.push({
                label: 'Goods Value',
                value: goodsValue,
            });
        }
        const notes = data.get('remarks');
        if (typeof notes === 'string' && notes.trim() !== '') {
            additionalFields.push({
                label: 'Notes',
                value: notes.trim(),
            });
        }

        const sections: FieldReviewSection[] = [
            {
                title: 'Bilty Information',
                fields: [
                    {
                        label: 'Bilty Number',
                        value:
                            (typeof lrNumber === 'string'
                                ? lrNumber.trim()
                                : '') || 'Auto',
                    },
                    {
                        label: 'Bilty Date',
                        value:
                            (typeof lrDate === 'string' ? lrDate.trim() : '') ||
                            '—',
                    },
                    { label: 'Customer', value: customerName },
                    { label: 'Vehicle Number', value: vehicleLabel },
                    { label: 'Vehicle Ownership', value: ownershipLabel },
                    {
                        label: 'Driver',
                        value: substituteDriverLabel ?? primaryDriverLabel,
                    },
                ],
            },
            {
                title: 'Route Information',
                fields: [
                    { label: 'Consignor', value: consignorName },
                    { label: 'Consignee', value: consigneeName },
                    { label: 'From', value: loadingStation },
                    { label: 'To', value: unloadingStation },
                    {
                        label: 'Reference By',
                        value: referenceBy.trim() || '—',
                    },
                ],
            },
            {
                title: 'Goods and Freight',
                layout: 'full',
                table: {
                    headers: goodsTableHeaders,
                    rows: goodsTableRows,
                },
                fields: financialSummaryFields,
            },
        ];

        if (selectedVehicle?.ownership === 'market' && showPayTo) {
            sections.push({
                title: 'Market Vehicle Information',
                fields: [
                    {
                        label: 'Truck Owner',
                        value: selectedVehicle?.owner_name ?? '—',
                    },
                    { label: 'Pay To', value: payeeTypeLabel },
                    { label: 'Payee', value: payeePartyLabel },
                ],
            });
        }

        if (selectedVehicle?.ownership === 'own') {
            const ownFields: FieldReview[] = [
                {
                    label: 'Assigned Driver',
                    value: primaryDriverLabel,
                },
            ];
            if (substituteDriverLabel) {
                ownFields.push({
                    label: 'Substitute Driver',
                    value: substituteDriverLabel,
                });
            }
            sections.push({
                title: 'Own Vehicle Information',
                fields: ownFields,
            });
        }

        if (additionalFields.length > 0) {
            sections.push({
                title: 'Additional Information',
                fields: additionalFields,
            });
        }

        setReviewSections(sections);
        setReviewFields([]);
        setReviewChanges([]);
        setConfirmOpen(true);
    };

    const handleBeforeSubmit = (): boolean => {
        if (!submitAllowed.current) {
            const form = document.getElementById('lorry-receipt-form');
            if (form instanceof HTMLFormElement) {
                openConfirmation(form);
            }
            return false;
        }
        submitAllowed.current = false;
        return true;
    };

    const submitAfterConfirm = (continuation: 'none' | 'hire' | 'trip') => {
        redirectToHire.current = continuation === 'hire';
        redirectToTrip.current = continuation === 'trip';
        submitAllowed.current = true;
        setConfirmOpen(false);
        window.setTimeout(() => {
            const form = document.getElementById(
                'lorry-receipt-form',
            ) as HTMLFormElement | null;
            const hireInput = form?.querySelector(
                '#redirect_to_hire',
            ) as HTMLInputElement | null;
            if (hireInput) {
                hireInput.value = continuation === 'hire' ? '1' : '0';
            }
            const tripInput = form?.querySelector(
                '#redirect_to_trip',
            ) as HTMLInputElement | null;
            if (tripInput) {
                tripInput.value = continuation === 'trip' ? '1' : '0';
            }
            form?.requestSubmit();
        }, 0);
    };

    const handleConfirm = () => {
        submitAfterConfirm('none');
    };

    const handleConfirmAndCreateHire = () => {
        submitAfterConfirm('hire');
    };

    const handleConfirmAndCreateTrip = () => {
        submitAfterConfirm('trip');
    };

    const handleSaveClick = (
        event: React.MouseEvent<HTMLButtonElement>,
    ): void => {
        if (submitAllowed.current) {
            return;
        }
        event.preventDefault();
        const form = document.getElementById(
            'lorry-receipt-form',
        ) as HTMLFormElement | null;
        if (form !== null && !form.reportValidity()) {
            return;
        }
        if (form !== null) {
            openConfirmation(form);
        }
    };

    return (
        <>
            <Head title={title} />
            <LrfPage
                title={title}
                subtitle={subtitle}
                backHref={urls.show ?? urls.index}
                actions={
                    urls.parties_store || urls.parties_create ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="lrf-btn lrf-btn--outline"
                            onClick={() => {
                                if (urls.parties_store) {
                                    setHeaderQuickCreate('customer');
                                    return;
                                }
                                if (urls.parties_create) {
                                    window.open(
                                        urls.parties_create,
                                        '_blank',
                                        'noopener,noreferrer',
                                    );
                                }
                            }}
                        >
                            + Add Customer
                        </Button>
                    ) : undefined
                }
            >
                <datalist id="stations-list">
                    {stations.map((station) => (
                        <option key={station.id} value={station.name} />
                    ))}
                </datalist>

                <Form
                    id="lorry-receipt-form"
                    action={action}
                    method={method}
                    className="w-full space-y-3"
                    onBefore={handleBeforeSubmit}
                >
                    {({ errors, processing }) => (
                        <>
                            <FormProcessingBridge
                                processing={processing}
                                onChange={setFormProcessing}
                            />
                            <input
                                type="hidden"
                                name="form_mode"
                                value={formMode}
                            />
                            <input
                                type="hidden"
                                id="redirect_to_hire"
                                name="redirect_to_hire"
                                value={redirectToHire.current ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                id="redirect_to_trip"
                                name="redirect_to_trip"
                                value={redirectToTrip.current ? '1' : '0'}
                            />
                            {resolvedContext ? (
                                <input
                                    type="hidden"
                                    name="workflow_context"
                                    value={resolvedContext}
                                />
                            ) : null}

                            <LrfSection
                                step={1}
                                title="Receipt Details"
                                description={
                                    isOwnVehicle
                                        ? 'Who is shipping, which own truck, and who drives'
                                        : 'Who is shipping, which truck, and who gets paid'
                                }
                                tone="receipt"
                            >
                                <div className="lrf-receipt-grid">
                                    <LrfReceiptCard title="Document">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {mode === 'create' ? (
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="lr_number">
                                                        Bilty Number
                                                    </Label>
                                                    <Input
                                                        id="lr_number"
                                                        name="lr_number"
                                                        className="h-9"
                                                        placeholder="Auto-generated if left blank"
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.lr_number
                                                        }
                                                    />
                                                </div>
                                            ) : (
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="lr_number_display">
                                                        Bilty Number
                                                    </Label>
                                                    <Input
                                                        id="lr_number_display"
                                                        className="h-9"
                                                        value={
                                                            lorryReceipt?.lr_number ??
                                                            ''
                                                        }
                                                        disabled
                                                        readOnly
                                                    />
                                                </div>
                                            )}
                                            <div className="space-y-1.5">
                                                <Label htmlFor="lr_date">
                                                    Bilty Date
                                                </Label>
                                                <Input
                                                    id="lr_date"
                                                    name="lr_date"
                                                    type="date"
                                                    className={dateFieldClass}
                                                    defaultValue={
                                                        mode === 'create'
                                                            ? todayIsoLocal()
                                                            : (lorryReceipt?.lr_date ??
                                                              '')
                                                    }
                                                />
                                                <InputError
                                                    message={errors.lr_date}
                                                />
                                            </div>
                                        </div>
                                        <SearchablePartySelect
                                            id="billing_customer_party_id"
                                            name="billing_customer_party_id"
                                            label="Billing Customer"
                                            required
                                            options={
                                                formOptions.billingCustomers ??
                                                []
                                            }
                                            searchUrl={urls.parties_options}
                                            role="customer"
                                            createStoreUrl={urls.parties_store}
                                            createHref={urls.parties_create}
                                            createLabel="Add"
                                            createRole="customer"
                                            value={billingCustomerId}
                                            onValueChange={setBillingCustomerId}
                                            emptyMessage="No eligible Customers in this branch."
                                            error={
                                                errors.billing_customer_party_id
                                            }
                                        />
                                    </LrfReceiptCard>

                                    {showVehicle ? (
                                        <LrfReceiptCard
                                            title={
                                                isOwnVehicle
                                                    ? 'Vehicle & Driver'
                                                    : 'Vehicle & Payee'
                                            }
                                            vehicle
                                        >
                                            <div className="lrf-receipt-card__row lrf-grid lrf-grid--2">
                                                <div className="space-y-1.5">
                                                    <SearchableVehicleSelect
                                                        id="vehicle_id"
                                                        name="vehicle_id"
                                                        label="Truck"
                                                        options={
                                                            formOptions.vehicles ??
                                                            []
                                                        }
                                                        searchUrl={
                                                            urls.vehicles_options
                                                        }
                                                        ownership={
                                                            allowedOwnershipValues.length >
                                                            0
                                                                ? allowedOwnershipValues
                                                                : undefined
                                                        }
                                                        value={vehicleId}
                                                        onValueChange={(
                                                            next,
                                                            option,
                                                        ) => {
                                                            setVehicleId(next);
                                                            setSelectedVehicleMeta(
                                                                option,
                                                            );
                                                        }}
                                                        required={
                                                            mode === 'create'
                                                        }
                                                        disabled={
                                                            mode === 'edit'
                                                        }
                                                        createStoreUrl={
                                                            urls.vehicles_store
                                                        }
                                                        createHref={
                                                            urls.vehicles_create
                                                        }
                                                        createLabel="Add"
                                                        partiesOptionsUrl={
                                                            urls.parties_options
                                                        }
                                                        partiesStoreUrl={
                                                            urls.parties_store
                                                        }
                                                        vehicleCatalogues={
                                                            normalizedVehicleCatalogues
                                                        }
                                                        defaultOwnership={
                                                            defaultQuickVehicleOwnership
                                                        }
                                                        allowOwnQuickCreate={
                                                            allowOwnQuickCreate
                                                        }
                                                        allowMarketQuickCreate={
                                                            allowMarketQuickCreate
                                                        }
                                                        emptyMessage="No eligible vehicles in this branch."
                                                        error={
                                                            errors.vehicle_id
                                                        }
                                                    />
                                                    {ownershipBadge ? (
                                                        <div className="flex justify-end">
                                                            {ownershipBadge}
                                                        </div>
                                                    ) : null}
                                                </div>

                                                {showPayTo ? (
                                                    <div className="space-y-1.5">
                                                        <Label>Pay To</Label>
                                                        <LrfPayPills
                                                            value={
                                                                intendedPayeeType
                                                            }
                                                            ownerUnavailable={
                                                                !marketVehicleHasOwner
                                                            }
                                                            onChange={(
                                                                next,
                                                            ) => {
                                                                setIntendedPayeeType(
                                                                    next,
                                                                );
                                                                if (
                                                                    next ===
                                                                    'agency'
                                                                ) {
                                                                    setIntendedPayeePartyId(
                                                                        '',
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="intended_payee_type"
                                                            value={
                                                                intendedPayeeType
                                                            }
                                                        />
                                                        {!marketVehicleHasOwner ? (
                                                            <p className="text-xs text-muted-foreground">
                                                                This truck has
                                                                no owner on
                                                                record, so
                                                                payment goes to
                                                                an Agency.
                                                            </p>
                                                        ) : null}
                                                        <InputError
                                                            message={
                                                                errors.intended_payee_type
                                                            }
                                                        />
                                                    </div>
                                                ) : null}
                                            </div>

                                            {isOwnVehicle ? (
                                                <div className="space-y-3">
                                                    <div className="grid gap-3 sm:grid-cols-2">
                                                        <div className="space-y-1.5">
                                                            <Label>
                                                                Assigned Primary
                                                                Driver
                                                            </Label>
                                                            <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm font-medium">
                                                                {selectedVehicle?.primary_driver_name ??
                                                                    'No primary driver assigned'}
                                                            </div>
                                                            {mode ===
                                                                'create' &&
                                                            !selectedVehicle?.primary_driver_party_id ? (
                                                                <p className="text-xs font-medium text-amber-600">
                                                                    No primary
                                                                    driver is
                                                                    assigned to
                                                                    this
                                                                    vehicle.
                                                                    Enable
                                                                    Substitute
                                                                    Driver below
                                                                    or assign a
                                                                    Primary
                                                                    Driver in
                                                                    Vehicle
                                                                    Drivers.
                                                                </p>
                                                            ) : null}
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label>
                                                                Ownership
                                                            </Label>
                                                            <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm">
                                                                Own fleet — one
                                                                driver per Trip
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {mode === 'create' ? (
                                                        <div className="space-y-2 rounded-md border border-border bg-muted/20 p-3">
                                                            <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        useSubstituteDriver
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) => {
                                                                        const checked =
                                                                            e
                                                                                .target
                                                                                .checked;
                                                                        setUseSubstituteDriver(
                                                                            checked,
                                                                        );
                                                                        if (
                                                                            !checked
                                                                        ) {
                                                                            setSubstituteDriverPartyId(
                                                                                '',
                                                                            );
                                                                        }
                                                                    }}
                                                                />
                                                                Use substitute
                                                                driver for this
                                                                Trip
                                                            </label>
                                                            <input
                                                                type="hidden"
                                                                name="use_substitute_driver"
                                                                value={
                                                                    useSubstituteDriver
                                                                        ? '1'
                                                                        : '0'
                                                                }
                                                            />
                                                            {useSubstituteDriver ? (
                                                                <div className="space-y-1.5 pt-1">
                                                                    <SearchablePartySelect
                                                                        id="substitute_driver_party_id"
                                                                        name="substitute_driver_party_id"
                                                                        label="Substitute Driver"
                                                                        options={
                                                                            formOptions.drivers ??
                                                                            []
                                                                        }
                                                                        searchUrl={
                                                                            urls.parties_options
                                                                        }
                                                                        role="driver"
                                                                        createStoreUrl={
                                                                            urls.parties_store
                                                                        }
                                                                        createLabel="Add"
                                                                        createRole="driver"
                                                                        value={
                                                                            substituteDriverPartyId
                                                                        }
                                                                        onValueChange={
                                                                            setSubstituteDriverPartyId
                                                                        }
                                                                        required
                                                                        emptyMessage="No eligible drivers in this branch."
                                                                        error={
                                                                            errors.substitute_driver_party_id
                                                                        }
                                                                    />
                                                                    <p className="text-xs text-muted-foreground">
                                                                        Applies
                                                                        only to
                                                                        this
                                                                        Trip.
                                                                        The
                                                                        vehicle&apos;s
                                                                        permanent
                                                                        Primary
                                                                        Driver
                                                                        assignment
                                                                        will not
                                                                        change.
                                                                    </p>
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            ) : null}

                                            {showPayeeRow ? (
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <div className="space-y-1.5">
                                                        {intendedPayeeType ===
                                                        'owner' ? (
                                                            <>
                                                                <Label>
                                                                    Truck Owner
                                                                </Label>
                                                                <input
                                                                    type="hidden"
                                                                    name="intended_payee_party_id"
                                                                    value={
                                                                        intendedPayeePartyId
                                                                    }
                                                                />
                                                                <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm font-medium">
                                                                    {selectedVehicle?.owner_name ??
                                                                        '—'}
                                                                </div>
                                                                {!selectedVehicle?.owner_party_id ? (
                                                                    <p
                                                                        className="rounded-md border border-amber-500/40 bg-amber-500/10 px-2 py-1.5 text-xs text-amber-950"
                                                                        role="status"
                                                                    >
                                                                        Truck
                                                                        owner is
                                                                        missing
                                                                        on this
                                                                        vehicle.
                                                                        Link an
                                                                        owner
                                                                        party
                                                                        before
                                                                        hire /
                                                                        TDS.
                                                                    </p>
                                                                ) : null}
                                                            </>
                                                        ) : (
                                                            <SearchablePartySelect
                                                                id="intended_payee_party_id"
                                                                name="intended_payee_party_id"
                                                                label="Agency"
                                                                options={[]}
                                                                searchUrl={
                                                                    urls.parties_options
                                                                }
                                                                role="transporter"
                                                                createStoreUrl={
                                                                    urls.parties_store
                                                                }
                                                                createHref={
                                                                    urls.parties_create
                                                                }
                                                                createLabel="Add"
                                                                createRole="transporter"
                                                                value={
                                                                    intendedPayeePartyId
                                                                }
                                                                onValueChange={
                                                                    setIntendedPayeePartyId
                                                                }
                                                                onPartyChange={
                                                                    setAgencyPayeeParty
                                                                }
                                                                required
                                                                emptyMessage="Search transporter agency…"
                                                            />
                                                        )}
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>
                                                            Driver / TDS
                                                        </Label>
                                                        <LrfDriverTdsSummary
                                                            driverName={
                                                                selectedVehicle?.primary_driver_name
                                                            }
                                                            tdsLabel={
                                                                tdsPreviewLabel
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            ) : null}

                                            {selectedVehicle?.owner_declaration_missing ? (
                                                <p
                                                    className="rounded-md border border-amber-500/40 bg-amber-500/10 px-2 py-1.5 text-xs text-amber-950"
                                                    role="status"
                                                >
                                                    Vehicle owner declaration is
                                                    incomplete. Update the
                                                    transporter declaration
                                                    before creating Lorry Hire /
                                                    TDS calculations.
                                                    {selectedVehicle.owner_name
                                                        ? ` Owner: ${selectedVehicle.owner_name}.`
                                                        : ''}
                                                </p>
                                            ) : null}
                                            <InputError
                                                message={
                                                    errors.workflow_context
                                                }
                                            />
                                        </LrfReceiptCard>
                                    ) : null}
                                </div>
                            </LrfSection>

                            {needsTextLocations ||
                            needsStations ||
                            needsParties ||
                            partiesOptional ? (
                                <LrfSection
                                    step={2}
                                    title="Parties & Route"
                                    description="Goods travel from consignor to consignee."
                                    tone="route"
                                >
                                    <div
                                        className="lrf-party-flow"
                                        id="lrPartyFlow"
                                    >
                                        <LrfPartyCard
                                            badge="FROM"
                                            badgeTone="from"
                                            footer={
                                                needsTextLocations ? (
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="from_text">
                                                            Loading Station
                                                        </Label>
                                                        <Input
                                                            id="from_text"
                                                            name="from_text"
                                                            className="h-9"
                                                            list="stations-list"
                                                            placeholder="Optional"
                                                            value={fromText}
                                                            onChange={(event) =>
                                                                setFromText(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.from_text
                                                            }
                                                        />
                                                    </div>
                                                ) : needsStations ? (
                                                    <div className="space-y-1.5">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <Label
                                                                htmlFor="from_station_id"
                                                                required
                                                            >
                                                                Loading Station
                                                            </Label>
                                                            {canQuickCreateCatalogues &&
                                                            urls.stations_store ? (
                                                                <button
                                                                    type="button"
                                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                                    onClick={() =>
                                                                        setStationQuickCreateTarget(
                                                                            'from',
                                                                        )
                                                                    }
                                                                >
                                                                    + Add
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                        <select
                                                            id="from_station_id"
                                                            name="from_station_id"
                                                            className={
                                                                fieldClass
                                                            }
                                                            value={
                                                                fromStationId
                                                            }
                                                            onChange={(event) =>
                                                                setFromStationId(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        >
                                                            <option value="">
                                                                Select
                                                            </option>
                                                            {stations.map(
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
                                                ) : null
                                            }
                                        >
                                            {needsParties || partiesOptional ? (
                                                <SearchablePartySelect
                                                    id="consignor_party_id"
                                                    name="consignor_party_id"
                                                    label="Consignor"
                                                    options={
                                                        formOptions.consignors
                                                    }
                                                    searchUrl={
                                                        urls.parties_options
                                                    }
                                                    role="consignor"
                                                    createStoreUrl={
                                                        urls.parties_store
                                                    }
                                                    createHref={
                                                        urls.parties_create
                                                    }
                                                    createLabel="Add"
                                                    createRole="consignor"
                                                    required={needsParties}
                                                    value={consignorPartyId}
                                                    defaultValue={
                                                        lorryReceipt?.consignor_party_id ??
                                                        ''
                                                    }
                                                    onValueChange={
                                                        setConsignorPartyId
                                                    }
                                                    onPartyChange={
                                                        setConsignorParty
                                                    }
                                                    emptyMessage="No eligible Consignors in this branch."
                                                    error={
                                                        errors.consignor_party_id
                                                    }
                                                />
                                            ) : null}
                                            <LrfPartyMetaGrid
                                                items={[
                                                    {
                                                        label: 'Contact',
                                                        value:
                                                            consignorParty?.contact_number ??
                                                            '—',
                                                    },
                                                    {
                                                        label: 'City',
                                                        value:
                                                            consignorParty?.city ??
                                                            '—',
                                                    },
                                                    {
                                                        label: 'State',
                                                        value:
                                                            consignorParty?.state ??
                                                            '—',
                                                    },
                                                ]}
                                            />
                                        </LrfPartyCard>

                                        <LrfRouteConnector
                                            routeHint={routeFlowHint}
                                            ready={Boolean(
                                                (consignorPartyId ||
                                                    consignorParty) &&
                                                (consigneePartyId ||
                                                    consigneeParty),
                                            )}
                                        />

                                        <LrfPartyCard
                                            badge="TO"
                                            badgeTone="to"
                                            footer={
                                                needsTextLocations ? (
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="to_text">
                                                            Unloading Station
                                                        </Label>
                                                        <Input
                                                            id="to_text"
                                                            name="to_text"
                                                            className="h-9"
                                                            list="stations-list"
                                                            placeholder="Optional"
                                                            value={toText}
                                                            onChange={(event) =>
                                                                setToText(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.to_text
                                                            }
                                                        />
                                                    </div>
                                                ) : needsStations ? (
                                                    <div className="space-y-1.5">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <Label
                                                                htmlFor="to_station_id"
                                                                required
                                                            >
                                                                Unloading
                                                                Station
                                                            </Label>
                                                            {canQuickCreateCatalogues &&
                                                            urls.stations_store ? (
                                                                <button
                                                                    type="button"
                                                                    className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                                    onClick={() =>
                                                                        setStationQuickCreateTarget(
                                                                            'to',
                                                                        )
                                                                    }
                                                                >
                                                                    + Add
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                        <select
                                                            id="to_station_id"
                                                            name="to_station_id"
                                                            className={
                                                                fieldClass
                                                            }
                                                            value={toStationId}
                                                            onChange={(event) =>
                                                                setToStationId(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        >
                                                            <option value="">
                                                                Select
                                                            </option>
                                                            {stations.map(
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
                                                ) : null
                                            }
                                        >
                                            {needsParties || partiesOptional ? (
                                                <SearchablePartySelect
                                                    id="consignee_party_id"
                                                    name="consignee_party_id"
                                                    label="Consignee"
                                                    options={
                                                        formOptions.consignees
                                                    }
                                                    searchUrl={
                                                        urls.parties_options
                                                    }
                                                    role="consignee"
                                                    createStoreUrl={
                                                        urls.parties_store
                                                    }
                                                    createHref={
                                                        urls.parties_create
                                                    }
                                                    createLabel="Add"
                                                    createRole="consignee"
                                                    required={needsParties}
                                                    value={consigneePartyId}
                                                    defaultValue={
                                                        lorryReceipt?.consignee_party_id ??
                                                        ''
                                                    }
                                                    onValueChange={
                                                        setConsigneePartyId
                                                    }
                                                    onPartyChange={
                                                        setConsigneeParty
                                                    }
                                                    emptyMessage="No eligible Consignees in this branch."
                                                    error={
                                                        errors.consignee_party_id
                                                    }
                                                />
                                            ) : null}
                                            <LrfPartyMetaGrid
                                                items={[
                                                    {
                                                        label: 'Contact',
                                                        value:
                                                            consigneeParty?.contact_number ??
                                                            '—',
                                                    },
                                                    {
                                                        label: 'City',
                                                        value:
                                                            consigneeParty?.city ??
                                                            '—',
                                                    },
                                                    {
                                                        label: 'State',
                                                        value:
                                                            consigneeParty?.state ??
                                                            '—',
                                                    },
                                                ]}
                                            />
                                        </LrfPartyCard>
                                    </div>
                                </LrfSection>
                            ) : null}

                            <LrfSection
                                step={3}
                                title="Goods & Charges"
                                description="Cargo details and auto-calculated freight amount"
                                tone="goods"
                            >
                                <div className="space-y-3">
                                    <LrfInnerPanel
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
                                                            <div className="flex items-center justify-between gap-2">
                                                                <Label className="text-xs">
                                                                    Goods Type
                                                                </Label>
                                                                {canQuickCreateCatalogues &&
                                                                urls.goods_types_store ? (
                                                                    <button
                                                                        type="button"
                                                                        className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                                        onClick={() =>
                                                                            setCatalogueQuickCreate(
                                                                                {
                                                                                    kind: 'goods',
                                                                                    rowIndex:
                                                                                        index,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        + Add
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                            <select
                                                                name={`goods[${index}][goods_type_id]`}
                                                                className={
                                                                    fieldClass
                                                                }
                                                                value={
                                                                    row.goods_type_id
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            goods_type_id:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'other',
                                                                    )
                                                                }
                                                            >
                                                                <option value="">
                                                                    Select
                                                                </option>
                                                                {goodsTypes.map(
                                                                    (item) => (
                                                                        <option
                                                                            key={
                                                                                item.id
                                                                            }
                                                                            value={
                                                                                item.id
                                                                            }
                                                                        >
                                                                            {
                                                                                item.name
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.goods_type_id`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <Label className="text-xs">
                                                                    Package Type
                                                                </Label>
                                                                {canQuickCreateCatalogues &&
                                                                urls.package_types_store ? (
                                                                    <button
                                                                        type="button"
                                                                        className="text-xs font-medium text-[#2D8CFF] hover:underline"
                                                                        onClick={() =>
                                                                            setCatalogueQuickCreate(
                                                                                {
                                                                                    kind: 'package',
                                                                                    rowIndex:
                                                                                        index,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        + Add
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                            <select
                                                                name={`goods[${index}][package_type_id]`}
                                                                className={
                                                                    fieldClass
                                                                }
                                                                value={
                                                                    row.package_type_id
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            package_type_id:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'other',
                                                                    )
                                                                }
                                                            >
                                                                <option value="">
                                                                    Select
                                                                </option>
                                                                {packageTypes.map(
                                                                    (item) => (
                                                                        <option
                                                                            key={
                                                                                item.id
                                                                            }
                                                                            value={
                                                                                item.id
                                                                            }
                                                                        >
                                                                            {
                                                                                item.name
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.package_type_id`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label
                                                                htmlFor={`goods_${index}_package_count`}
                                                                className="text-xs"
                                                                required
                                                            >
                                                                No. of Units
                                                            </Label>
                                                            <Input
                                                                id={`goods_${index}_package_count`}
                                                                name={`goods[${index}][package_count]`}
                                                                type="number"
                                                                min="1"
                                                                step="1"
                                                                required
                                                                className="h-9"
                                                                value={
                                                                    row.package_count
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            package_count:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'package_count',
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.package_count`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label className="text-xs">
                                                                Wt./Unit (MT)
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
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'weight_per_unit',
                                                                    )
                                                                }
                                                                aria-label={`Weight per unit MT row ${index + 1}`}
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label
                                                                htmlFor={`goods_${index}_actual_weight`}
                                                                className="text-xs"
                                                                required
                                                            >
                                                                Actual Weight
                                                                (MT)
                                                            </Label>
                                                            <input
                                                                type="hidden"
                                                                name={`goods[${index}][actual_weight]`}
                                                                value={mtToKg(
                                                                    row.actual_weight,
                                                                )}
                                                            />
                                                            <Input
                                                                id={`goods_${index}_actual_weight`}
                                                                type="number"
                                                                min="0"
                                                                step="0.001"
                                                                required
                                                                className="h-9"
                                                                value={
                                                                    row.actual_weight
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            actual_weight:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'actual_weight',
                                                                    )
                                                                }
                                                                aria-label={`Actual weight MT row ${index + 1}`}
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.actual_weight`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <Label
                                                                    htmlFor={`goods_${index}_charged_weight`}
                                                                    className="text-xs"
                                                                    required
                                                                >
                                                                    Charged
                                                                    Weight (MT)
                                                                </Label>
                                                                {row.charged_weight !==
                                                                    row.actual_weight &&
                                                                row.actual_weight !==
                                                                    '' ? (
                                                                    <button
                                                                        type="button"
                                                                        className="text-[11px] font-medium text-[#2A6FDB] underline"
                                                                        onClick={() =>
                                                                            applyGoodsField(
                                                                                index,
                                                                                {},
                                                                                'match_actual',
                                                                            )
                                                                        }
                                                                    >
                                                                        Match
                                                                        Actual
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                            {row.id ? (
                                                                <input
                                                                    type="hidden"
                                                                    name={`goods[${index}][id]`}
                                                                    value={
                                                                        row.id
                                                                    }
                                                                />
                                                            ) : null}
                                                            <input
                                                                type="hidden"
                                                                name={`goods[${index}][charged_weight]`}
                                                                value={mtToKg(
                                                                    row.charged_weight,
                                                                )}
                                                            />
                                                            <Input
                                                                id={`goods_${index}_charged_weight`}
                                                                type="number"
                                                                min="0"
                                                                step="0.001"
                                                                required
                                                                className="h-9"
                                                                value={
                                                                    row.charged_weight
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            charged_weight:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'charged_weight',
                                                                    )
                                                                }
                                                                aria-label={`Charged weight MT row ${index + 1}`}
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.charged_weight`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                    <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                                        {mayViewFreight ? (
                                                            <>
                                                                <div className="space-y-1">
                                                                    <Label
                                                                        htmlFor={`goods_${index}_rate_type`}
                                                                        className="text-xs"
                                                                        required
                                                                    >
                                                                        Rate
                                                                        Type
                                                                    </Label>
                                                                    <input
                                                                        type="hidden"
                                                                        name={`goods[${index}][rate_semantics]`}
                                                                        value={
                                                                            row.rate_semantics
                                                                        }
                                                                    />
                                                                    <select
                                                                        id={`goods_${index}_rate_type`}
                                                                        name={`goods[${index}][freight_rate_type]`}
                                                                        className={
                                                                            fieldClass
                                                                        }
                                                                        value={
                                                                            row.freight_rate_type
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
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
                                                                        {rateTypeOptionsForRow(
                                                                            row,
                                                                            formOptions.rateTypeOptions,
                                                                            mode ===
                                                                                'create' ||
                                                                                index >=
                                                                                    initialGoodsRowCount.current,
                                                                        ).map(
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
                                                                    <InputError
                                                                        message={
                                                                            errors[
                                                                                `goods.${index}.freight_rate_type`
                                                                            ]
                                                                        }
                                                                    />
                                                                </div>
                                                                <div className="space-y-1">
                                                                    <Label
                                                                        htmlFor={`goods_${index}_freight_rate`}
                                                                        className="text-xs"
                                                                        required
                                                                    >
                                                                        Rate
                                                                    </Label>
                                                                    <Input
                                                                        id={`goods_${index}_freight_rate`}
                                                                        name={`goods[${index}][freight_rate]`}
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.0001"
                                                                        className="h-9"
                                                                        value={
                                                                            row.freight_rate
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
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
                                                                    <InputError
                                                                        message={
                                                                            errors[
                                                                                `goods.${index}.freight_rate`
                                                                            ]
                                                                        }
                                                                    />
                                                                </div>
                                                                <div className="space-y-1">
                                                                    <Label className="text-xs">
                                                                        Freight
                                                                        Amount
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
                                                                value={
                                                                    row.goods_value
                                                                }
                                                                onChange={(e) =>
                                                                    applyGoodsField(
                                                                        index,
                                                                        {
                                                                            goods_value:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'other',
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.goods_value`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1 md:col-span-2 xl:col-span-2">
                                                            <Label className="flex items-center gap-1 text-xs">
                                                                <span>
                                                                    Goods
                                                                    Description
                                                                </span>
                                                                <span className="font-normal text-muted-foreground">
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
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'other',
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors[
                                                                        `goods.${index}.goods_description`
                                                                    ]
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                    <div className="flex justify-end">
                                                        {goods.length > 1 ? (
                                                            <Button
                                                                type="button"
                                                                variant="destructive"
                                                                size="sm"
                                                                className="gap-1.5"
                                                                onClick={() =>
                                                                    setGoods(
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
                                                        ) : null}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </LrfInnerPanel>

                                    <LrfInnerPanel
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
                                                                <Label required>
                                                                    Number
                                                                </Label>
                                                            </th>
                                                            <th className="px-2 py-2 font-medium">
                                                                Date
                                                            </th>
                                                            <th className="px-2 py-2 font-medium">
                                                                <Label required>
                                                                    Valid until
                                                                </Label>
                                                            </th>
                                                            <th className="px-2 py-2 font-medium">
                                                                Remove
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {ewbs.map(
                                                            (row, index) => (
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
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
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
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
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
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
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
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </LrfInnerPanel>

                                    <LrfInnerPanel
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
                                                                <Label required>
                                                                    Description
                                                                </Label>
                                                            </th>
                                                            <th className="px-2 py-2 font-medium">
                                                                <Label required>
                                                                    Amount
                                                                </Label>
                                                            </th>
                                                            <th className="px-2 py-2 font-medium">
                                                                Remove
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {charges.map(
                                                            (row, index) => (
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
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
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
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
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
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </LrfInnerPanel>

                                    <LrfSummaryMetrics
                                        items={[
                                            {
                                                name: 'Weight (MT)',
                                                value: summary.totalCharged,
                                            },
                                            {
                                                name: 'Units',
                                                value: summary.totalUnits,
                                            },
                                            ...(mayViewFreight
                                                ? [
                                                      {
                                                          name: 'Freight',
                                                          value: `₹${summary.totalFreight}`,
                                                      },
                                                      {
                                                          name: 'Total Amount',
                                                          value: `₹${summary.totalAmount}`,
                                                          hero: true,
                                                      },
                                                  ]
                                                : [
                                                      {
                                                          name: 'Goods Value',
                                                          value: `₹${summary.totalGoodsValue}`,
                                                          hero: true,
                                                      },
                                                  ]),
                                        ]}
                                    />
                                </div>
                            </LrfSection>

                            <LrfSection
                                step={4}
                                title="Optional Details"
                                description="External GR, client invoice, declared cargo value, and reference. These do not change freight."
                                tone="receipt"
                            >
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="space-y-1">
                                        <Label htmlFor="external_gr_number">
                                            GR Number
                                        </Label>
                                        <Input
                                            id="external_gr_number"
                                            name="external_gr_number"
                                            value={externalGrNumber}
                                            onChange={(e) =>
                                                setExternalGrNumber(
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="External / reference GR"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="client_invoice_number">
                                            Invoice Number
                                        </Label>
                                        <Input
                                            id="client_invoice_number"
                                            name="client_invoice_number"
                                            value={clientInvoiceNumber}
                                            onChange={(e) =>
                                                setClientInvoiceNumber(
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Client material / E-Way invoice"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="goods_value">
                                            Goods Value
                                        </Label>
                                        <Input
                                            id="goods_value"
                                            name="goods_value"
                                            value={declaredGoodsValue}
                                            onChange={(e) =>
                                                setDeclaredGoodsValue(
                                                    e.target.value,
                                                )
                                            }
                                            inputMode="decimal"
                                            placeholder={
                                                summary.totalGoodsValue !==
                                                '0.00'
                                                    ? `Sum of rows ₹${summary.totalGoodsValue}`
                                                    : 'Declared cargo value'
                                            }
                                        />
                                        <p className="text-[11px] text-slate-500">
                                            Leave blank to use the sum of
                                            goods-row values. Not freight
                                            revenue.
                                        </p>
                                    </div>
                                    <div className="space-y-1">
                                        <SearchablePartySelect
                                            id="reference_by_party_id"
                                            name="reference_by_party_id"
                                            label="Reference By"
                                            options={[]}
                                            value={referenceByPartyId}
                                            onValueChange={(next) => {
                                                setReferenceByPartyId(next);
                                                if (next === '') {
                                                    setReferenceBy('');
                                                }
                                            }}
                                            searchUrl={urls.parties_options}
                                            role="general_vendor"
                                            roles={[
                                                'general_vendor',
                                                'customer',
                                                'transporter',
                                            ]}
                                            createStoreUrl={urls.parties_store}
                                            createHref={urls.parties_create}
                                            createLabel="Add"
                                            createRole="customer"
                                            emptyMessage="No eligible reference parties in this branch."
                                        />
                                        {referenceBy && !referenceByPartyId ? (
                                            <p className="text-[11px] text-muted-foreground">
                                                Historical text reference:{' '}
                                                {referenceBy}
                                            </p>
                                        ) : null}
                                        <input
                                            type="hidden"
                                            name="reference_by"
                                            value={referenceBy}
                                        />
                                    </div>
                                </div>
                            </LrfSection>

                            <LrfActions>
                                <Button
                                    variant="outline"
                                    asChild
                                    className="lrf-btn lrf-btn--outline"
                                >
                                    <Link href={urls.show ?? urls.index}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button
                                    type="submit"
                                    className="lrf-btn lrf-btn--brand"
                                    disabled={processing}
                                    onClick={handleSaveClick}
                                >
                                    Save Bilty
                                </Button>
                            </LrfActions>
                        </>
                    )}
                </Form>

                <FormConfirmationDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    processing={formProcessing}
                    mode={mode === 'create' ? 'create' : 'edit'}
                    title={
                        mode === 'create'
                            ? 'Create this Bilty?'
                            : 'Save Bilty changes?'
                    }
                    description="Review vehicle, customer, weights, and freight before submitting."
                    fields={reviewFields}
                    sections={
                        reviewSections.length > 0 ? reviewSections : undefined
                    }
                    changes={reviewChanges}
                    onConfirm={handleConfirm}
                    confirmLabel={
                        mode === 'create' ? 'Save Bilty' : 'Confirm save'
                    }
                    onSecondaryConfirm={
                        mode === 'create' &&
                        resolvedContext === 'broker' &&
                        canCreateLorryHire
                            ? handleConfirmAndCreateHire
                            : mode === 'create' &&
                                resolvedContext === 'own_fleet' &&
                                canCreateTrip
                              ? handleConfirmAndCreateTrip
                              : undefined
                    }
                    secondaryConfirmLabel={
                        mode === 'create' &&
                        resolvedContext === 'broker' &&
                        canCreateLorryHire
                            ? 'Save & Create Lorry Hire'
                            : mode === 'create' &&
                                resolvedContext === 'own_fleet' &&
                                canCreateTrip
                              ? 'Save & Manage Expenses'
                              : undefined
                    }
                />

                {urls.parties_store && headerQuickCreate ? (
                    <QuickCreatePartySheet
                        open={headerQuickCreate !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setHeaderQuickCreate(null);
                            }
                        }}
                        storeUrl={urls.parties_store}
                        role={headerQuickCreate}
                        onCreated={(party) => {
                            setBillingCustomerId(party.id);
                            setHeaderQuickCreate(null);
                        }}
                    />
                ) : null}

                {catalogueQuickCreate ? (
                    <QuickCreateCatalogueSheet
                        open={catalogueQuickCreate !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setCatalogueQuickCreate(null);
                            }
                        }}
                        title={
                            catalogueQuickCreate.kind === 'goods'
                                ? 'Goods Type'
                                : 'Package Type'
                        }
                        storeUrl={
                            catalogueQuickCreate.kind === 'goods'
                                ? (urls.goods_types_store ?? '')
                                : (urls.package_types_store ?? '')
                        }
                        onCreated={(item: QuickCreatedCatalogueItem) => {
                            const option = {
                                id: item.id,
                                name: item.name,
                            };
                            if (catalogueQuickCreate.kind === 'goods') {
                                setGoodsTypes((current) => {
                                    if (
                                        current.some(
                                            (entry) => entry.id === item.id,
                                        )
                                    ) {
                                        return current;
                                    }
                                    return [...current, option];
                                });
                                applyGoodsField(
                                    catalogueQuickCreate.rowIndex,
                                    { goods_type_id: item.id },
                                    'other',
                                );
                            } else {
                                setPackageTypes((current) => {
                                    if (
                                        current.some(
                                            (entry) => entry.id === item.id,
                                        )
                                    ) {
                                        return current;
                                    }
                                    return [...current, option];
                                });
                                applyGoodsField(
                                    catalogueQuickCreate.rowIndex,
                                    { package_type_id: item.id },
                                    'other',
                                );
                            }
                            setCatalogueQuickCreate(null);
                        }}
                    />
                ) : null}

                {stationQuickCreateTarget && urls.stations_store ? (
                    <QuickCreateStationSheet
                        open={stationQuickCreateTarget !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setStationQuickCreateTarget(null);
                            }
                        }}
                        storeUrl={urls.stations_store}
                        onCreated={(station: QuickCreatedStation) => {
                            setStations((current) => {
                                if (
                                    current.some(
                                        (entry) => entry.id === station.id,
                                    )
                                ) {
                                    return current;
                                }
                                return [
                                    ...current,
                                    {
                                        id: station.id,
                                        name: station.name,
                                    },
                                ];
                            });
                            if (stationQuickCreateTarget === 'from') {
                                setFromStationId(station.id);
                            } else {
                                setToStationId(station.id);
                            }
                            setStationQuickCreateTarget(null);
                        }}
                    />
                ) : null}
            </LrfPage>
        </>
    );
}
