import { useEffect, useMemo, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type PartyOption = {
    id: string;
    name: string;
    city?: string | null;
    contact_number?: string | null;
};

type Props = {
    id: string;
    name: string;
    label: string;
    options: PartyOption[];
    /** Server search endpoint (e.g. /parties/options). When set, searches beyond the initial option list. */
    searchUrl?: string;
    /** Required role for remote search (e.g. customer). */
    role?: string;
    /** Optional multi-role remote search. */
    roles?: string[];
    required?: boolean;
    defaultValue?: string;
    value?: string;
    onValueChange?: (value: string) => void;
    emptyMessage?: string;
    createHref?: string;
    createLabel?: string;
    error?: string;
    className?: string;
    disabled?: boolean;
};

const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

function optionLabel(option: PartyOption): string {
    const parts = [option.name];
    if (option.city) {
        parts.push(option.city);
    }
    if (option.contact_number) {
        parts.push(option.contact_number);
    }
    return parts.join(' · ');
}

/**
 * Searchable party selector for role-filtered master options.
 * Supports optional server-side search for large Party masters.
 */
export function SearchablePartySelect({
    id,
    name,
    label,
    options,
    searchUrl,
    role,
    roles,
    required = false,
    defaultValue = '',
    value,
    onValueChange,
    emptyMessage = 'No eligible parties available.',
    createHref,
    createLabel = 'Create party',
    error,
    className,
    disabled = false,
}: Props) {
    const [query, setQuery] = useState('');
    const [internalValue, setInternalValue] = useState(defaultValue);
    const [remoteOptions, setRemoteOptions] = useState<PartyOption[] | null>(
        null,
    );
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
        if (!searchUrl || !role) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams();
            params.set('role', role);
            if (roles && roles.length > 0) {
                for (const item of roles) {
                    params.append('roles[]', item);
                }
            }
            if (query.trim() !== '') {
                params.set('q', query.trim());
            }
            params.set('limit', '50');
            if (selected !== '') {
                params.set('selected_id', selected);
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
                        throw new Error('Party search failed.');
                    }
                    const payload = (await response.json()) as {
                        data?: PartyOption[];
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
                    setSearchError('Unable to search parties.');
                })
                .finally(() => {
                    setLoading(false);
                });
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, role, roles, searchUrl, selected]);

    const selectedStillVisible =
        selected === '' || filtered.some((option) => option.id === selected);

    const selectedFallbackName =
        options.find((option) => option.id === selected)?.name ??
        remoteOptions?.find((option) => option.id === selected)?.name ??
        'Selected party';

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
                            onValueChange?.(next);
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
