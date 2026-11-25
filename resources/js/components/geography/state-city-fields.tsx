import { QuickCreateCitySheet } from '@/components/geography/quick-create-city-sheet';
import { QuickCreateStateSheet } from '@/components/geography/quick-create-state-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import {
    readCanManageGeography,
    readCanQuickCreateGeography,
} from '@/lib/geography-permissions';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type GeographyOption = {
    id: string;
    name: string;
};

type ValueMode = 'ids' | 'names';

type Props = {
    stateId?: string | null;
    cityId?: string | null;
    stateName?: string | null;
    cityName?: string | null;
    valueMode?: ValueMode;
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

const CUSTOM_ID_PREFIX = 'custom:';

function isCustomGeographyId(id: string): boolean {
    return id.startsWith(CUSTOM_ID_PREFIX);
}

function isRealUuid(id: string): boolean {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
        id,
    );
}

function resolveSelectedId(
    id: string,
    name: string,
    valueMode: ValueMode,
): string {
    if (id !== '') {
        return id;
    }
    if (valueMode === 'names' && name !== '') {
        return `${CUSTOM_ID_PREFIX}${name}`;
    }
    return '';
}

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
 * Default `ids` mode is Master-only (no custom text).
 * `names` mode is CRM Lead-only: Master selection or free-text.
 * Changing State clears an incompatible City.
 */
export function StateCityFields({
    stateId = '',
    cityId = '',
    stateName = '',
    cityName = '',
    valueMode = 'ids',
    required = false,
    disabled = false,
    stateError,
    cityError,
    canQuickCreate,
    stateStoreUrl,
    cityStoreUrl,
    onChange,
}: Props) {
    const page = usePage();
    const canManageMasters = readCanManageGeography(page.props);
    const canManage = canQuickCreate ?? readCanQuickCreateGeography(page.props);
    const resolvedStateStoreUrl =
        stateStoreUrl ??
        (canManageMasters ? '/admin/states' : '/api/geography/states');
    const resolvedCityStoreUrl =
        cityStoreUrl ??
        (canManageMasters ? '/admin/cities' : '/api/geography/cities');

    const [selectedStateId, setSelectedStateId] = useState(() =>
        resolveSelectedId(stateId ?? '', stateName ?? '', valueMode),
    );
    const [selectedCityId, setSelectedCityId] = useState(() =>
        resolveSelectedId(cityId ?? '', cityName ?? '', valueMode),
    );
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
        setSelectedStateId(
            resolveSelectedId(stateId ?? '', stateName ?? '', valueMode),
        );
        setSelectedStateName(stateName ?? '');
    }, [stateId, stateName, valueMode]);

    useEffect(() => {
        setSelectedCityId(
            resolveSelectedId(cityId ?? '', cityName ?? '', valueMode),
        );
        setSelectedCityName(cityName ?? '');
    }, [cityId, cityName, valueMode]);

    const useNamesMode = valueMode === 'names';
    const stateHasSelection =
        selectedStateId !== '' || selectedStateName !== '';
    const cityFieldDisabled =
        disabled ||
        (useNamesMode ? !stateHasSelection : selectedStateId === '');

    const submittedStateId = isRealUuid(selectedStateId) ? selectedStateId : '';
    const submittedCityId = isRealUuid(selectedCityId) ? selectedCityId : '';

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
                    name={useNamesMode ? 'state_lookup' : 'state_id'}
                    label="State"
                    options={stateOptions}
                    value={selectedStateId}
                    required={required}
                    disabled={disabled}
                    error={stateError}
                    searchUrl="/api/geography/states"
                    buildSearchParams={(query) => {
                        const params = new URLSearchParams();
                        if (query.trim() !== '') {
                            params.set('q', query.trim());
                        }
                        return params;
                    }}
                    mapResponse={mapStates}
                    emptyMessage={
                        canManage
                            ? 'No matching State. Use + Add to create one.'
                            : 'No matching State found.'
                    }
                    placeholder="Search state…"
                    addAction={stateAddAction}
                    allowCustomValue={useNamesMode}
                    onValueChange={(id, option) => {
                        if (
                            !useNamesMode &&
                            id !== '' &&
                            (isCustomGeographyId(id) || !isRealUuid(id))
                        ) {
                            return;
                        }
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
                {useNamesMode ? (
                    <>
                        <input
                            type="hidden"
                            name="state"
                            value={selectedStateName}
                        />
                        <input
                            type="hidden"
                            name="state_id"
                            value={submittedStateId}
                        />
                    </>
                ) : null}
                <SearchableCombobox
                    id="city_id"
                    name={useNamesMode ? 'city_lookup' : 'city_id'}
                    label="City"
                    options={cityOptions}
                    value={selectedCityId}
                    required={required}
                    disabled={cityFieldDisabled}
                    error={cityError}
                    searchUrl="/api/geography/cities"
                    buildSearchParams={(query) => {
                        const params = new URLSearchParams();
                        if (
                            selectedStateId !== '' &&
                            !isCustomGeographyId(selectedStateId)
                        ) {
                            params.set('state_id', selectedStateId);
                        }
                        if (query.trim() !== '') {
                            params.set('q', query.trim());
                        }
                        return params;
                    }}
                    mapResponse={mapCities}
                    emptyMessage={
                        !stateHasSelection
                            ? 'Select a state first.'
                            : canManage
                              ? 'No matching City. Use + Add to create one.'
                              : 'No cities in this state.'
                    }
                    placeholder="Search city…"
                    addAction={cityAddAction}
                    allowCustomValue={useNamesMode}
                    onValueChange={(id, option) => {
                        if (
                            !useNamesMode &&
                            id !== '' &&
                            (isCustomGeographyId(id) || !isRealUuid(id))
                        ) {
                            return;
                        }
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
                {useNamesMode ? (
                    <>
                        <input
                            type="hidden"
                            name="city"
                            value={selectedCityName}
                        />
                        <input
                            type="hidden"
                            name="city_id"
                            value={submittedCityId}
                        />
                    </>
                ) : null}
            </div>

            {canManage ? (
                <>
                    <QuickCreateStateSheet
                        open={stateQuickCreateOpen}
                        onOpenChange={setStateQuickCreateOpen}
                        storeUrl={resolvedStateStoreUrl}
                        catalogueOnly={!canManageMasters}
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
                        storeUrl={resolvedCityStoreUrl}
                        initialStateId={
                            isRealUuid(selectedStateId) ? selectedStateId : ''
                        }
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
