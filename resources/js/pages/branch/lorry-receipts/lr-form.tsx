import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import { normalizeComparable } from '@/components/form-confirmation/diff';
import type {
    ChangeReview,
    FieldReview,
} from '@/components/form-confirmation/types';
import InputError from '@/components/input-error';
import {
    QuickCreatePartySheet,
    type QuickCreatePartyRole,
} from '@/components/masters/quick-create-party-sheet';
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
type NamedOption = { id: string; name: string; code?: string };
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
        vehicleQuickCreate?: {
            truckTypes: NamedOption[];
            tyreLayouts: NamedOption[];
            drivers: NamedOption[];
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
    };
    permissions?: {
        can_view_freight?: boolean;
        can_create_lorry_hire?: boolean;
        can_create_trip?: boolean;
    };
};

type GoodsFieldSource =
    | 'weight_per_unit'
    | 'actual_weight'
    | 'package_count'
    | 'charged_weight'
    | 'match_actual'
    | 'other';

const NEW_ROW_RATE_TYPE_VALUES = new Set([
    'fixed_amount',
    'per_package_unit',
    'per_charged_weight',
    'per_actual_weight',
]);

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
    freight_rate_type: 'per_charged_weight',
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
            ? `Rate ${row.freight_rate || '—'} (${row.freight_rate_type || '—'})`
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
        });
        return;
    }
    if (previous !== '' && next === '') {
        changes.push({
            label,
            previous,
            next: '—',
            changeType: 'removed',
        });
        return;
    }
    changes.push({
        label,
        previous: previous || '—',
        next: next || '—',
        changeType: 'modified',
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
    const formMode =
        formModeProp ?? (workflowContext === 'partload' ? 'partload' : 'truck');
    const submitAllowed = useRef(false);
    const redirectToHire = useRef(false);
    const redirectToTrip = useRef(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [formProcessing, setFormProcessing] = useState(false);
    const [reviewFields, setReviewFields] = useState<FieldReview[]>([]);
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
    const [agencyPayeeParty, setAgencyPayeeParty] =
        useState<PartyOption | null>(null);
    const [headerQuickCreate, setHeaderQuickCreate] =
        useState<QuickCreatePartyRole | null>(null);
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
            selectedVehicleMeta?.label ??
            selectedVehicleMeta?.registration_number ??
            formOptions.vehicles?.find((option) => option.id === vehicleId)
                ?.label ??
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
            );
            pushScalarChange(
                changes,
                'Consignor',
                formOptions.consignors.find(
                    (option) => option.id === lorryReceipt.consignor_party_id,
                )?.name ?? '—',
                consignorName,
            );
            pushScalarChange(
                changes,
                'Consignee',
                formOptions.consignees.find(
                    (option) => option.id === lorryReceipt.consignee_party_id,
                )?.name ?? '—',
                consigneeName,
            );
            pushScalarChange(
                changes,
                'Loading Station',
                resolveLocationDisplay(
                    lorryReceipt.from_text,
                    lorryReceipt.from_station_id,
                    formOptions.stations,
                ),
                loadingStation,
            );
            pushScalarChange(
                changes,
                'Unloading Station',
                resolveLocationDisplay(
                    lorryReceipt.to_text,
                    lorryReceipt.to_station_id,
                    formOptions.stations,
                ),
                unloadingStation,
            );

            const originalGoods = mapGoodsFromReceipt(lorryReceipt.goods);
            const goodsTypeOptions = formOptions.goodsTypes ?? [];
            const packageTypeOptions = formOptions.packageTypes ?? [];
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
            );

            const originalSummary = computeLrSummary(
                originalGoods,
                originalCharges,
            );
            pushScalarChange(
                changes,
                'Actual Wt (MT)',
                originalSummary.totalActual,
                summary.totalActual,
            );
            pushScalarChange(
                changes,
                'Charged Wt (MT)',
                originalSummary.totalCharged,
                summary.totalCharged,
            );
            if (mayViewFreight) {
                pushScalarChange(
                    changes,
                    'Freight',
                    originalSummary.totalFreight,
                    summary.totalFreight,
                );
                pushScalarChange(
                    changes,
                    'Additional Charges Total',
                    originalSummary.totalAdditional,
                    summary.totalAdditional,
                );
                pushScalarChange(
                    changes,
                    'Total Amount',
                    originalSummary.totalAmount,
                    summary.totalAmount,
                );
            }

            setReviewChanges(changes);
            setReviewFields([]);
            setConfirmOpen(true);
            return;
        }

        const lrNumber = data.get('lr_number');
        const lrDate = data.get('lr_date');
        const fields: FieldReview[] = [
            {
                label: 'Bilty No.',
                value:
                    (typeof lrNumber === 'string' ? lrNumber.trim() : '') ||
                    'Auto',
                group: 'Document & Customer',
            },
            {
                label: 'Bilty Date',
                value: (typeof lrDate === 'string' ? lrDate.trim() : '') || '—',
                group: 'Document & Customer',
            },
            {
                label: 'Customer',
                value: customerName,
                group: 'Document & Customer',
            },
            {
                label: 'Vehicle',
                value: vehicleLabel,
                group: 'Vehicle & Operations',
            },
            ...(showPayTo
                ? [
                      {
                          label: 'Pay To',
                          value: payeeTypeLabel,
                          group: 'Vehicle & Operations',
                      },
                      {
                          label: 'Payee',
                          value: payeePartyLabel,
                          group: 'Vehicle & Operations',
                      },
                  ]
                : []),
            {
                label: 'Consignor',
                value: consignorName,
                group: 'Parties & Route',
            },
            {
                label: 'Consignee',
                value: consigneeName,
                group: 'Parties & Route',
            },
            {
                label: 'From',
                value: loadingStation,
                group: 'Parties & Route',
            },
            {
                label: 'To',
                value: unloadingStation,
                group: 'Parties & Route',
            },
        ];

        const goodsTypeOptions = formOptions.goodsTypes ?? [];
        const packageTypeOptions = formOptions.packageTypes ?? [];
        goods.forEach((row, index) => {
            fields.push({
                label: `Goods ${index + 1}`,
                value: formatGoodsRowReview(
                    row,
                    index,
                    goodsTypeOptions,
                    packageTypeOptions,
                    mayViewFreight,
                ),
                group: 'Goods',
            });
        });

        if (ewbs.length > 0) {
            fields.push({
                label: 'E-Way Bills',
                value: formatEwbReview(ewbs),
                group: 'Optional',
            });
        }

        if (charges.length > 0) {
            fields.push({
                label: 'Additional Charges',
                value: formatChargesReview(charges),
                group: 'Optional',
            });
        }

        fields.push(
            {
                label: 'GR Number',
                value: externalGrNumber.trim() || '—',
                group: 'Optional Details',
            },
            {
                label: 'Invoice Number',
                value: clientInvoiceNumber.trim() || '—',
                group: 'Optional Details',
            },
            {
                label: 'Goods Value',
                value: declaredGoodsValue.trim() || summary.totalGoodsValue,
                group: 'Optional Details',
            },
            {
                label: 'Reference By',
                value: referenceBy.trim() || '—',
                group: 'Optional Details',
            },
        );

        fields.push(
            {
                label: 'Actual Wt (MT)',
                value: summary.totalActual,
                group: 'Totals',
            },
            {
                label: 'Charged Wt (MT)',
                value: summary.totalCharged,
                group: 'Totals',
            },
        );
        if (mayViewFreight) {
            fields.push(
                {
                    label: 'Freight',
                    value: summary.totalFreight,
                    group: 'Totals',
                },
                {
                    label: 'Additional Charges Total',
                    value: summary.totalAdditional,
                    group: 'Totals',
                },
                {
                    label: 'Total Billable',
                    value: summary.totalAmount,
                    group: 'Totals',
                },
            );
        }
        setReviewFields(fields);
        setReviewChanges([]);
        setConfirmOpen(true);
    };

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        if (!submitAllowed.current) {
            event.preventDefault();
            openConfirmation(event.currentTarget);
            return;
        }
        submitAllowed.current = false;
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
                    {formOptions.stations.map((station) => (
                        <option key={station.id} value={station.name} />
                    ))}
                </datalist>

                <Form
                    id="lorry-receipt-form"
                    action={action}
                    method={method}
                    className="w-full space-y-3"
                    onSubmit={handleSubmit}
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
                                                            formOptions.vehicleQuickCreate
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
                                                    </div>
                                                ) : null}
                                            </div>

                                            {isOwnVehicle ? (
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <div className="space-y-1.5">
                                                        <Label>
                                                            Primary Driver
                                                        </Label>
                                                        <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm font-medium">
                                                            {selectedVehicle?.primary_driver_name ??
                                                                '—'}
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">
                                                            Substitute driver is
                                                            chosen when
                                                            continuing to Trip /
                                                            Manage Expenses.
                                                        </p>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>Ownership</Label>
                                                        <div className="flex min-h-9 items-center rounded-md border border-input bg-muted/20 px-3 text-sm">
                                                            Own fleet — no
                                                            market Pay To /
                                                            Agency.
                                                        </div>
                                                    </div>
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
                                                        <Label htmlFor="from_station_id">
                                                            Loading Station *
                                                        </Label>
                                                        <select
                                                            id="from_station_id"
                                                            name="from_station_id"
                                                            className={
                                                                fieldClass
                                                            }
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
                                                        <Label htmlFor="to_station_id">
                                                            Unloading Station *
                                                        </Label>
                                                        <select
                                                            id="to_station_id"
                                                            name="to_station_id"
                                                            className={
                                                                fieldClass
                                                            }
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
                                                            <Label className="text-xs">
                                                                Goods Type
                                                            </Label>
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
                                                                            {
                                                                                item.name
                                                                            }
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
                                                                            {
                                                                                item.name
                                                                            }
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
                                                                min="1"
                                                                step="1"
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
                                                            <Label className="text-xs">
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
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'actual_weight',
                                                                    )
                                                                }
                                                                aria-label={`Actual weight MT row ${index + 1}`}
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <div className="flex items-center justify-between gap-2">
                                                                <Label className="text-xs">
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
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                        'charged_weight',
                                                                    )
                                                                }
                                                                aria-label={`Charged weight MT row ${index + 1}`}
                                                            />
                                                        </div>
                                                    </div>
                                                    <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                                                        {mayViewFreight ? (
                                                            <>
                                                                <div className="space-y-1">
                                                                    <Label className="text-xs">
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
            </LrfPage>
        </>
    );
}
