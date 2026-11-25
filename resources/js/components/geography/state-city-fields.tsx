import { QuickCreateCitySheet } from '@/components/geography/quick-create-city-sheet';
import { QuickCreateStateSheet } from '@/components/geography/quick-create-state-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { readCanManageGeography } from '@/lib/geography-permissions';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type GeographyOption = {
    id: string;
    name: string;
};

type Props = {
    stateId?: string | null;
    cityId?: string | null;
    stateName?: string | null;
    cityName?: string | null;
    required?: boolean;
    disabled?: boolean;
    stateError?: string;
    cityError?: string;
    canQuickCreate?: boolean;
    stateStoreUrl?: string;
    cityStoreUrl?: string;
    onChange?: (value: {
        state_id: string;
        city_id: string;
        state: string;
        city: string;
    }) => void;
};

function mapStates(json: unknown): ComboboxOption[] {
    const data = (json as { data?: GeographyOption[] })?.data ?? [];
    return data.map((row) => ({ id: row.id, label: row.name }));
}

function mapCities(json: unknown): ComboboxOption[] {
    const data = (json as { data?: GeographyOption[] })?.data ?? [];
    return data.map((row) => ({ id: row.id, label: row.name }));
}

function mergeOptions(
    primary: ComboboxOption[],
    extras: ComboboxOption[],
): ComboboxOption[] {
    const map = new Map<string, ComboboxOption>();
    for (const option of [...primary, ...extras]) {
        map.set(option.id, option);
    }
    return [...map.values()];
}

/**
 * Dependent State → City selectors with remote search.
 * Changing State clears an incompatible City.
 */
export function StateCityFields({
    stateId = '',
    cityId = '',
    stateName = '',
    cityName = '',
    required = false,
    disabled = false,
    stateError,
    cityError,
    canQuickCreate,
    stateStoreUrl = '/admin/states',
    cityStoreUrl = '/admin/cities',
    onChange,
}: Props) {
    const page = usePage();
    const canManage = canQuickCreate ?? readCanManageGeography(page.props);

    const [selectedStateId, setSelectedStateId] = useState(stateId ?? '');
    const [selectedCityId, setSelectedCityId] = useState(cityId ?? '');
    const [selectedStateName, setSelectedStateName] = useState(stateName ?? '');
    const [selectedCityName, setSelectedCityName] = useState(cityName ?? '');
    const [extraStateOptions, setExtraStateOptions] = useState<
        ComboboxOption[]
    >([]);
    const [extraCityOptions, setExtraCityOptions] = useState<ComboboxOption[]>(
        [],
    );
    const [stateQuickCreateOpen, setStateQuickCreateOpen] = useState(false);
    const [cityQuickCreateOpen, setCityQuickCreateOpen] = useState(false);

    useEffect(() => {
        setSelectedStateId(stateId ?? '');
        setSelectedStateName(stateName ?? '');
    }, [stateId, stateName]);

    useEffect(() => {
        setSelectedCityId(cityId ?? '');
        setSelectedCityName(cityName ?? '');
    }, [cityId, cityName]);

    const emit = useCallback(
        (next: {
            state_id: string;
            city_id: string;
            state: string;
            city: string;
        }) => {
            onChange?.(next);
        },
        [onChange],
    );

    const stateOptions = useMemo(
        () =>
            mergeOptions(
                selectedStateId && selectedStateName
                    ? [{ id: selectedStateId, label: selectedStateName }]
                    : [],
                extraStateOptions,
            ),
        [extraStateOptions, selectedStateId, selectedStateName],
    );

    const cityOptions = useMemo(
        () =>
            mergeOptions(
                selectedCityId && selectedCityName
                    ? [{ id: selectedCityId, label: selectedCityName }]
                    : [],
                extraCityOptions,
            ),
        [extraCityOptions, selectedCityId, selectedCityName],
    );

    const stateAddAction = canManage
        ? {
              label: '+ Add',
              onClick: () => setStateQuickCreateOpen(true),
          }
        : undefined;

    const cityAddAction = canManage
        ? {
              label: '+ Add',
              onClick: () => setCityQuickCreateOpen(true),
          }
        : undefined;

    return (
        <>
            <div className="grid gap-4 md:grid-cols-2">
                <SearchableCombobox
                    id="state_id"
                    name="state_id"
                    label="State"
                    options={stateOptions}
                    value={selectedStateId}
                    required={required}
                    disabled={disabled}
                    error={stateError}
                    searchUrl="/api/geography/states"
                    mapResponse={mapStates}
                    emptyMessage="No states found."
                    placeholder="Search state…"
                    addAction={stateAddAction}
                    onValueChange={(id, option) => {
                        const nextState = id;
                        const nextStateName = option?.label ?? '';
                        setSelectedStateId(nextState);
                        setSelectedStateName(nextStateName);
                        setSelectedCityId('');
                        setSelectedCityName('');
                        emit({
                            state_id: nextState,
                            city_id: '',
                            state: nextStateName,
                            city: '',
                        });
                    }}
                />
                <input type="hidden" name="state" value={selectedStateName} />
                <SearchableCombobox
                    id="city_id"
                    name="city_id"
                    label="City"
                    options={cityOptions}
                    value={selectedCityId}
                    required={required}
                    disabled={disabled || !selectedStateId}
                    error={cityError}
                    searchUrl="/api/geography/cities"
                    buildSearchParams={(query) => {
                        const params = new URLSearchParams();
                        params.set('state_id', selectedStateId);
                        if (query.trim() !== '') {
                            params.set('q', query.trim());
                        }
                        return params;
                    }}
                    mapResponse={mapCities}
                    emptyMessage={
                        selectedStateId
                            ? 'No cities in this state.'
                            : 'Select a state first.'
                    }
                    placeholder="Search city…"
                    addAction={cityAddAction}
                    onValueChange={(id, option) => {
                        const nextCity = id;
                        const nextCityName = option?.label ?? '';
                        setSelectedCityId(nextCity);
                        setSelectedCityName(nextCityName);
                        emit({
                            state_id: selectedStateId,
                            city_id: nextCity,
                            state: selectedStateName,
                            city: nextCityName,
                        });
                    }}
                />
                <input type="hidden" name="city" value={selectedCityName} />
            </div>

            {canManage ? (
                <>
                    <QuickCreateStateSheet
                        open={stateQuickCreateOpen}
                        onOpenChange={setStateQuickCreateOpen}
                        storeUrl={stateStoreUrl}
                        nested
                        onCreated={(created) => {
                            const option = {
                                id: created.id,
                                label: created.name,
                            };
                            setExtraStateOptions((current) =>
                                current.some((item) => item.id === option.id)
                                    ? current
                                    : [...current, option],
                            );
                            setSelectedStateId(created.id);
                            setSelectedStateName(created.name);
                            setSelectedCityId('');
                            setSelectedCityName('');
                            setExtraCityOptions([]);
                            emit({
                                state_id: created.id,
                                city_id: '',
                                state: created.name,
                                city: '',
                            });
                        }}
                    />
                    <QuickCreateCitySheet
                        open={cityQuickCreateOpen}
                        onOpenChange={setCityQuickCreateOpen}
                        storeUrl={cityStoreUrl}
                        initialStateId={selectedStateId}
                        initialStateName={selectedStateName}
                        nested
                        onCreated={(created) => {
                            const cityOption = {
                                id: created.id,
                                label: created.name,
                            };
                            setExtraCityOptions((current) =>
                                current.some(
                                    (item) => item.id === cityOption.id,
                                )
                                    ? current
                                    : [...current, cityOption],
                            );
                            if (created.state_id !== selectedStateId) {
                                const stateLabel = created.state_name ?? '';
                                if (stateLabel !== '') {
                                    setExtraStateOptions((current) => {
                                        const stateOption = {
                                            id: created.state_id,
                                            label: stateLabel,
                                        };
                                        if (
                                            current.some(
                                                (item) =>
                                                    item.id === stateOption.id,
                                            )
                                        ) {
                                            return current;
                                        }
                                        return [...current, stateOption];
                                    });
                                }
                                setSelectedStateId(created.state_id);
                                setSelectedStateName(stateLabel);
                            }
                            setSelectedCityId(created.id);
                            setSelectedCityName(created.name);
                            emit({
                                state_id: created.state_id,
                                city_id: created.id,
                                state:
                                    created.state_id === selectedStateId
                                        ? selectedStateName
                                        : (created.state_name ?? ''),
                                city: created.name,
                            });
                        }}
                    />
                </>
            ) : null}
        </>
    );
}
