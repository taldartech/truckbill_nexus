import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { useCallback, useEffect, useState } from 'react';

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
    onChange,
}: Props) {
    const [selectedStateId, setSelectedStateId] = useState(stateId ?? '');
    const [selectedCityId, setSelectedCityId] = useState(cityId ?? '');
    const [selectedStateName, setSelectedStateName] = useState(stateName ?? '');
    const [selectedCityName, setSelectedCityName] = useState(cityName ?? '');

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

    const stateOptions: ComboboxOption[] =
        selectedStateId && selectedStateName
            ? [{ id: selectedStateId, label: selectedStateName }]
            : [];

    const cityOptions: ComboboxOption[] =
        selectedCityId && selectedCityName
            ? [{ id: selectedCityId, label: selectedCityName }]
            : [];

    return (
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
    );
}
