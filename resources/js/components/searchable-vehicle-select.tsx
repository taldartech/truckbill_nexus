import {
    QuickCreateVehicleSheet,
    type VehicleQuickCreateCatalogues,
} from '@/components/masters/quick-create-vehicle-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { useCallback, useMemo, useRef, useState } from 'react';

export type VehicleSelectOption = {
    id: string;
    registration_number: string;
    ownership?: string | null;
    ownership_label?: string | null;
    label?: string;
    owner_party_id?: string | null;
    owner_name?: string | null;
    owner_declaration_missing?: boolean;
    primary_driver_party_id?: string | null;
    primary_driver_name?: string | null;
};

type Props = {
    id: string;
    name: string;
    label: string;
    options: VehicleSelectOption[];
    searchUrl?: string;
    ownership?: string | string[];
    required?: boolean;
    defaultValue?: string;
    value?: string;
    onValueChange?: (value: string, option: VehicleSelectOption | null) => void;
    emptyMessage?: string;
    createHref?: string;
    createLabel?: string;
    createStoreUrl?: string;
    /** Party options endpoint for Market truck-owner quick create. */
    partiesOptionsUrl?: string;
    partiesStoreUrl?: string;
    vehicleCatalogues?: VehicleQuickCreateCatalogues;
    defaultOwnership?: 'market' | 'own';
    allowOwnQuickCreate?: boolean;
    allowMarketQuickCreate?: boolean;
    error?: string;
    className?: string;
    disabled?: boolean;
};

/** Secondary line: own vehicles stay identifiable; market vehicles show only recorded owner and driver. */
function vehicleOptionDescription(option: VehicleSelectOption): string | null {
    const parts = [
        option.ownership === 'own' ? 'Own vehicle' : option.owner_name,
        option.primary_driver_name
            ? `Driver: ${option.primary_driver_name}`
            : null,
    ].filter((part): part is string => Boolean(part && part.trim() !== ''));

    return parts.length > 0 ? parts.join(' · ') : null;
}

function toComboboxOption(option: VehicleSelectOption): ComboboxOption {
    return {
        id: option.id,
        label: option.registration_number,
        description: vehicleOptionDescription(option),
        keywords: [option.owner_name, option.primary_driver_name].filter(
            (keyword): keyword is string => Boolean(keyword),
        ),
    };
}

/**
 * Searchable vehicle selector with optional server-side search for large fleets.
 */
export function SearchableVehicleSelect({
    id,
    name,
    label,
    options,
    searchUrl,
    ownership,
    required = false,
    defaultValue = '',
    value,
    onValueChange,
    emptyMessage = 'No eligible vehicles available.',
    createHref,
    createLabel = 'Add',
    createStoreUrl,
    partiesOptionsUrl,
    partiesStoreUrl,
    vehicleCatalogues,
    defaultOwnership = 'market',
    allowOwnQuickCreate = true,
    allowMarketQuickCreate = true,
    error,
    className,
    disabled = false,
}: Props) {
    const vehicleByIdRef = useRef<Map<string, VehicleSelectOption>>(new Map());
    const [extraOptions, setExtraOptions] = useState<VehicleSelectOption[]>([]);
    const [quickCreateOpen, setQuickCreateOpen] = useState(false);

    const mergedOptions = useMemo(() => {
        const map = new Map<string, VehicleSelectOption>();
        for (const option of options) {
            map.set(option.id, option);
        }
        for (const option of extraOptions) {
            map.set(option.id, option);
        }
        vehicleByIdRef.current = map;
        return [...map.values()];
    }, [options, extraOptions]);

    const comboboxOptions = useMemo(
        () => mergedOptions.map(toComboboxOption),
        [mergedOptions],
    );

    const canQuickCreate = Boolean(createStoreUrl && createStoreUrl !== '');

    const buildSearchParams = useCallback(
        (query: string, selectedId: string) => {
            const params = new URLSearchParams();
            if (query.trim() !== '') {
                params.set('q', query.trim());
            }
            params.set('limit', '50');
            if (selectedId !== '') {
                params.set('selected_id', selectedId);
            }
            const ownerships = Array.isArray(ownership)
                ? ownership
                : ownership
                  ? [ownership]
                  : [];
            for (const item of ownerships) {
                params.append('ownership[]', item);
            }
            return params;
        },
        [ownership],
    );

    const mapResponse = useCallback((json: unknown): ComboboxOption[] => {
        const payload = json as { data?: VehicleSelectOption[] };
        const data = payload.data ?? [];
        for (const option of data) {
            vehicleByIdRef.current.set(option.id, option);
        }
        return data.map(toComboboxOption);
    }, []);

    const addAction = canQuickCreate
        ? {
              label: createLabel,
              onClick: () => setQuickCreateOpen(true),
          }
        : createHref && createHref !== ''
          ? { label: createLabel, href: createHref }
          : undefined;

    const showEmptyState = !searchUrl && mergedOptions.length === 0;

    return (
        <>
            <SearchableCombobox
                id={id}
                name={name}
                label={label}
                options={comboboxOptions}
                value={value}
                defaultValue={defaultValue}
                onValueChange={(nextId, comboboxOption) => {
                    const vehicle =
                        vehicleByIdRef.current.get(nextId) ??
                        mergedOptions.find((item) => item.id === nextId) ??
                        (comboboxOption
                            ? ({
                                  id: comboboxOption.id,
                                  registration_number: comboboxOption.label,
                              } satisfies VehicleSelectOption)
                            : null);
                    onValueChange?.(nextId, nextId === '' ? null : vehicle);
                }}
                searchUrl={searchUrl}
                buildSearchParams={searchUrl ? buildSearchParams : undefined}
                mapResponse={searchUrl ? mapResponse : undefined}
                required={required}
                disabled={disabled}
                clearable={!required}
                emptyMessage={emptyMessage}
                error={error}
                className={className}
                addAction={addAction}
                showEmptyState={showEmptyState}
            />
            {canQuickCreate && createStoreUrl ? (
                <QuickCreateVehicleSheet
                    open={quickCreateOpen}
                    onOpenChange={setQuickCreateOpen}
                    storeUrl={createStoreUrl}
                    partiesOptionsUrl={partiesOptionsUrl}
                    partiesStoreUrl={partiesStoreUrl}
                    catalogues={vehicleCatalogues}
                    defaultOwnership={defaultOwnership}
                    allowOwn={allowOwnQuickCreate}
                    allowMarket={allowMarketQuickCreate}
                    onCreated={(vehicle) => {
                        setExtraOptions((current) => {
                            if (
                                current.some((item) => item.id === vehicle.id)
                            ) {
                                return current;
                            }
                            return [...current, vehicle];
                        });
                        vehicleByIdRef.current.set(vehicle.id, vehicle);
                        onValueChange?.(vehicle.id, vehicle);
                    }}
                />
            ) : null}
        </>
    );
}
