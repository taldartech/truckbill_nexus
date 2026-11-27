import {
    QuickCreatePartySheet,
    type QuickCreatePartyRole,
} from '@/components/masters/quick-create-party-sheet';
import {
    SearchableCombobox,
    type ComboboxOption,
} from '@/components/searchable-combobox';
import { useCallback, useMemo, useRef, useState } from 'react';

export type PartyOption = {
    id: string;
    name: string;
    short_name?: string | null;
    city?: string | null;
    state?: string | null;
    city_id?: string | null;
    state_id?: string | null;
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
    onPartyChange?: (party: PartyOption | null) => void;
    emptyMessage?: string;
    /** Full-page create fallback when quick create is unavailable. */
    createHref?: string;
    createLabel?: string;
    /** JSON store URL for inline quick create (preferred over createHref). */
    createStoreUrl?: string;
    /** Role assigned when quick-creating from this selector. */
    createRole?: QuickCreatePartyRole;
    error?: string;
    className?: string;
    disabled?: boolean;
};

function partyOptionLabel(option: PartyOption): string {
    const shortName = option.short_name?.trim() ?? '';
    const parts = [
        shortName !== '' && shortName !== option.name
            ? `${option.name} (${shortName})`
            : option.name,
    ];
    if (option.city) {
        parts.push(option.city);
    }
    if (option.contact_number) {
        parts.push(option.contact_number);
    }
    return parts.join(' · ');
}

function toComboboxOption(option: PartyOption): ComboboxOption {
    return {
        id: option.id,
        label: partyOptionLabel(option),
        keywords: option.short_name ? [option.short_name] : undefined,
    };
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
    onPartyChange,
    emptyMessage = 'No eligible parties available.',
    createHref,
    createLabel = 'Add',
    createStoreUrl,
    createRole,
    error,
    className,
    disabled = false,
}: Props) {
    const partyByIdRef = useRef<Map<string, PartyOption>>(new Map());
    const [extraOptions, setExtraOptions] = useState<PartyOption[]>([]);
    const [quickCreateOpen, setQuickCreateOpen] = useState(false);

    const mergedOptions = useMemo(() => {
        const map = new Map<string, PartyOption>();
        for (const option of options) {
            map.set(option.id, option);
        }
        for (const option of extraOptions) {
            map.set(option.id, option);
        }
        partyByIdRef.current = map;
        return [...map.values()];
    }, [options, extraOptions]);

    const comboboxOptions = useMemo(
        () => mergedOptions.map(toComboboxOption),
        [mergedOptions],
    );

    const remoteEnabled = Boolean(searchUrl && role);
    const quickCreateRole =
        createRole ??
        (role === 'customer' ||
        role === 'consignor' ||
        role === 'consignee' ||
        role === 'transporter' ||
        role === 'driver' ||
        role === 'pump_vendor' ||
        role === 'general_vendor'
            ? role
            : undefined);
    const canQuickCreate = Boolean(
        createStoreUrl && createStoreUrl !== '' && quickCreateRole,
    );

    const buildSearchParams = useCallback(
        (query: string, selectedId: string) => {
            const params = new URLSearchParams();
            if (role) {
                params.set('role', role);
            }
            if (roles && roles.length > 0) {
                for (const item of roles) {
                    params.append('roles[]', item);
                }
            }
            if (query.trim() !== '') {
                params.set('q', query.trim());
            }
            params.set('limit', '50');
            if (selectedId !== '') {
                params.set('selected_id', selectedId);
            }
            return params;
        },
        [role, roles],
    );

    const mapResponse = useCallback((json: unknown): ComboboxOption[] => {
        const payload = json as { data?: PartyOption[] };
        const data = payload.data ?? [];
        for (const option of data) {
            partyByIdRef.current.set(option.id, option);
        }
        return data.map(toComboboxOption);
    }, []);

    const applyParty = useCallback(
        (party: PartyOption | null) => {
            const nextId = party?.id ?? '';
            onValueChange?.(nextId);
            onPartyChange?.(party);
        },
        [onPartyChange, onValueChange],
    );

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
                onValueChange={(nextId) => {
                    if (nextId === '') {
                        applyParty(null);
                        return;
                    }
                    const party =
                        partyByIdRef.current.get(nextId) ??
                        mergedOptions.find((item) => item.id === nextId) ??
                        null;
                    applyParty(party);
                }}
                searchUrl={remoteEnabled ? searchUrl : undefined}
                buildSearchParams={
                    remoteEnabled ? buildSearchParams : undefined
                }
                mapResponse={remoteEnabled ? mapResponse : undefined}
                required={required}
                disabled={disabled}
                clearable={!required}
                emptyMessage={emptyMessage}
                error={error}
                className={className}
                addAction={addAction}
                showEmptyState={showEmptyState}
            />
            {canQuickCreate && quickCreateRole && createStoreUrl ? (
                <QuickCreatePartySheet
                    open={quickCreateOpen}
                    onOpenChange={setQuickCreateOpen}
                    storeUrl={createStoreUrl}
                    role={quickCreateRole}
                    onCreated={(party) => {
                        setExtraOptions((current) => {
                            if (current.some((item) => item.id === party.id)) {
                                return current;
                            }
                            return [...current, party];
                        });
                        applyParty(party);
                    }}
                />
            ) : null}
        </>
    );
}
