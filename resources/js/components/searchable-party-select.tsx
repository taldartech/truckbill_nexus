import { useMemo, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type PartyOption = {
    id: string;
    name: string;
};

type Props = {
    id: string;
    name: string;
    label: string;
    options: PartyOption[];
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

/**
 * Searchable party selector for role-filtered master options.
 * Filters client-side within the bounded server option list.
 */
export function SearchablePartySelect({
    id,
    name,
    label,
    options,
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
    const selected = value ?? internalValue;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (q === '') {
            return options;
        }
        return options.filter((option) =>
            option.name.toLowerCase().includes(q),
        );
    }, [options, query]);

    const selectedStillVisible =
        selected === '' || filtered.some((option) => option.id === selected);

    return (
        <div className={cn('space-y-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {options.length === 0 ? (
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
                                {options.find((o) => o.id === selected)?.name ??
                                    'Selected party'}
                            </option>
                        ) : null}
                        {filtered.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </select>
                    {filtered.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            No matches for “{query}”.
                        </p>
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
