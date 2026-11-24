import { useEffect, useMemo, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

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
    error?: string;
    className?: string;
    disabled?: boolean;
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

function optionLabel(option: VehicleSelectOption): string {
    if (option.label && option.label.trim() !== '') {
        return option.label;
    }
    const parts = [option.registration_number];
    if (option.ownership_label) {
        parts.push(option.ownership_label);
    }
    if (option.owner_name) {
        parts.push(option.owner_name);
    }
    return parts.join(' · ');
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
    createLabel = 'Create vehicle',
    error,
    className,
    disabled = false,
}: Props) {
    const [query, setQuery] = useState('');
    const [internalValue, setInternalValue] = useState(defaultValue);
    const [remoteOptions, setRemoteOptions] = useState<
        VehicleSelectOption[] | null
    >(null);
    const [loading, setLoading] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const selected = value ?? internalValue;

    const sourceOptions = remoteOptions ?? options;

    const filtered = useMemo(() => {
        if (searchUrl) {
            return sourceOptions;
        }
        const q = query.trim().toLowerCase();
        if (q === '') {
            return sourceOptions;
        }
        return sourceOptions.filter((option) =>
            optionLabel(option).toLowerCase().includes(q),
        );
    }, [query, searchUrl, sourceOptions]);

    useEffect(() => {
        if (!searchUrl) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams();
            if (query.trim() !== '') {
                params.set('q', query.trim());
            }
            params.set('limit', '50');
            if (selected !== '') {
                params.set('selected_id', selected);
            }
            const ownerships = Array.isArray(ownership)
                ? ownership
                : ownership
                  ? [ownership]
                  : [];
            for (const item of ownerships) {
                params.append('ownership[]', item);
            }

            setLoading(true);
            setSearchError(null);

            void fetch(`${searchUrl}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('Vehicle search failed.');
                    }
                    const payload = (await response.json()) as {
                        data?: VehicleSelectOption[];
                    };
                    setRemoteOptions(payload.data ?? []);
                })
                .catch((error: unknown) => {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }
                    setSearchError('Unable to search vehicles.');
                })
                .finally(() => {
                    setLoading(false);
                });
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [ownership, query, searchUrl, selected]);

    const selectedStillVisible =
        selected === '' || filtered.some((option) => option.id === selected);

    const selectedOption =
        options.find((option) => option.id === selected) ??
        remoteOptions?.find((option) => option.id === selected) ??
        null;

    const selectedFallbackName =
        selectedOption !== null
            ? optionLabel(selectedOption)
            : 'Selected vehicle';

    const showEmptyState = !searchUrl && options.length === 0;

    return (
        <div className={cn('space-y-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {showEmptyState ? (
                <div className="space-y-2 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                    <p>{emptyMessage}</p>
                    {createHref ? (
                        <a
                            href={createHref}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            {createLabel}
                        </a>
                    ) : null}
                </div>
            ) : (
                <>
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={`Search ${label.toLowerCase()}…`}
                        aria-label={`Search ${label}`}
                        disabled={disabled}
                        autoComplete="off"
                    />
                    {loading ? (
                        <p className="text-xs text-muted-foreground">
                            Searching…
                        </p>
                    ) : null}
                    <select
                        id={id}
                        name={name}
                        required={required}
                        disabled={disabled}
                        className={fieldClass}
                        value={selected}
                        onChange={(event) => {
                            const next = event.target.value;
                            if (value === undefined) {
                                setInternalValue(next);
                            }
                            const nextOption =
                                filtered.find((option) => option.id === next) ??
                                options.find((option) => option.id === next) ??
                                remoteOptions?.find(
                                    (option) => option.id === next,
                                ) ??
                                null;
                            onValueChange?.(next, nextOption);
                        }}
                    >
                        <option value="" disabled={required}>
                            Select {label.toLowerCase()}
                        </option>
                        {!selectedStillVisible && selected !== '' ? (
                            <option value={selected}>
                                {selectedFallbackName}
                            </option>
                        ) : null}
                        {filtered.map((option) => (
                            <option key={option.id} value={option.id}>
                                {optionLabel(option)}
                            </option>
                        ))}
                    </select>
                    {filtered.length === 0 && !loading ? (
                        <p className="text-xs text-muted-foreground">
                            {query.trim() === ''
                                ? emptyMessage
                                : `No matches for “${query}”.`}
                        </p>
                    ) : null}
                    {searchError ? (
                        <p className="text-xs text-destructive" role="alert">
                            {searchError}
                        </p>
                    ) : null}
                    {createHref ? (
                        <a
                            href={createHref}
                            className="text-xs font-medium text-primary underline-offset-4 hover:underline"
                        >
                            {createLabel}
                        </a>
                    ) : null}
                </>
            )}
            {error ? (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
