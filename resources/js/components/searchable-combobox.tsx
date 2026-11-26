import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { ChevronDown, X } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type KeyboardEvent,
} from 'react';
import { createPortal } from 'react-dom';

export type ComboboxOption = {
    id: string;
    label: string;
    description?: string | null;
};

type AddAction = {
    label: string;
    onClick?: () => void;
    href?: string;
};

type Props = {
    id: string;
    name: string;
    label: string;
    options: ComboboxOption[];
    value?: string;
    defaultValue?: string;
    onValueChange?: (id: string, option: ComboboxOption | null) => void;
    searchUrl?: string;
    buildSearchParams?: (query: string, selectedId: string) => URLSearchParams;
    mapResponse?: (json: unknown) => ComboboxOption[];
    required?: boolean;
    disabled?: boolean;
    clearable?: boolean;
    emptyMessage?: string;
    placeholder?: string;
    error?: string;
    className?: string;
    addAction?: AddAction;
    /** When true, render only the empty dashed panel (no combobox). */
    showEmptyState?: boolean;
    /**
     * Opt-in free-text option ("Use custom value").
     * Disabled by default — Master forms must never enable this.
     * CRM Lead geography is the approved exception: custom text is offered
     * only after a settled search returns zero Master matches.
     */
    allowCustomValue?: boolean;
};

type PanelPosition = {
    top: number;
    left: number;
    width: number;
    maxHeight: number;
    placement: 'below' | 'above';
};

const CUSTOM_ID_PREFIX = 'custom:';
const PANEL_MAX_HEIGHT = 240;
const VIEWPORT_GAP = 8;

function findOption(
    options: ComboboxOption[],
    id: string,
): ComboboxOption | null {
    return options.find((option) => option.id === id) ?? null;
}

function isCustomOptionId(id: string): boolean {
    return id.startsWith(CUSTOM_ID_PREFIX);
}

function rankOptions(
    options: ComboboxOption[],
    query: string,
): ComboboxOption[] {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return options;
    }

    return [...options].sort((a, b) => {
        const score = (label: string | undefined): number => {
            const value = (label ?? '').toLowerCase();
            if (value === q) {
                return 0;
            }
            if (value.startsWith(q)) {
                return 1;
            }
            if (value.includes(q)) {
                return 2;
            }
            return 3;
        };

        const delta = score(a.label) - score(b.label);
        if (delta !== 0) {
            return delta;
        }
        return (a.label ?? '').localeCompare(b.label ?? '');
    });
}

function filterOptionsLocally(
    options: ComboboxOption[],
    query: string,
): ComboboxOption[] {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return options;
    }

    return options.filter((option) =>
        (option.label ?? '').toLowerCase().includes(q),
    );
}

function computePanelPosition(
    trigger: HTMLElement | null,
): PanelPosition | null {
    if (trigger === null || typeof window === 'undefined') {
        return null;
    }

    const rect = trigger.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom - VIEWPORT_GAP;
    const spaceAbove = rect.top - VIEWPORT_GAP;
    const preferBelow = spaceBelow >= Math.min(PANEL_MAX_HEIGHT, 160);
    const placement: 'below' | 'above' =
        preferBelow || spaceBelow >= spaceAbove ? 'below' : 'above';
    const available =
        placement === 'below'
            ? Math.max(120, spaceBelow)
            : Math.max(120, spaceAbove);
    const maxHeight = Math.min(PANEL_MAX_HEIGHT, available);
    const top =
        placement === 'below'
            ? rect.bottom + 4
            : Math.max(VIEWPORT_GAP, rect.top - 4 - maxHeight);

    return {
        top,
        left: rect.left,
        width: rect.width,
        maxHeight,
        placement,
    };
}

export function SearchableCombobox({
    id,
    name,
    label,
    options,
    value,
    defaultValue = '',
    onValueChange,
    searchUrl,
    buildSearchParams,
    mapResponse,
    required = false,
    disabled = false,
    clearable = true,
    emptyMessage = 'No options available.',
    placeholder,
    error,
    className,
    addAction,
    showEmptyState = false,
    allowCustomValue = false,
}: Props) {
    const listboxId = useId();
    const panelSearchId = useId();
    const rootRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const requestSeqRef = useRef(0);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [internalValue, setInternalValue] = useState(defaultValue);
    const [remoteOptions, setRemoteOptions] = useState<ComboboxOption[] | null>(
        null,
    );
    const [loading, setLoading] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    /** Last query that completed a successful remote search (null while unsettled). */
    const [settledQuery, setSettledQuery] = useState<string | null>(null);
    const [highlightedIndex, setHighlightedIndex] = useState(0);
    const [optionCache, setOptionCache] = useState<
        Record<string, ComboboxOption>
    >({});
    const [panelPosition, setPanelPosition] = useState<PanelPosition | null>(
        null,
    );

    const selected = value ?? internalValue;
    const resolvedPlaceholder =
        placeholder ?? `Select ${(label ?? 'option').toLowerCase()}…`;

    const sourceOptions = remoteOptions ?? options;

    const filtered = useMemo(() => {
        let list = filterOptionsLocally(sourceOptions, query);
        list = rankOptions(list, query);
        // Master records only — never keep stale custom rows in the candidate set.
        list = list.filter((option) => !isCustomOptionId(option.id));

        const trimmed = query.trim();
        const masterMatchesExist = list.length > 0;
        const remoteSearchSettled =
            searchUrl !== undefined &&
            !loading &&
            searchError === null &&
            settledQuery === trimmed;
        const localSearchSettled =
            searchUrl === undefined && !loading && searchError === null;
        const canOfferCustom =
            allowCustomValue &&
            trimmed.length >= 2 &&
            !masterMatchesExist &&
            (searchUrl !== undefined
                ? remoteSearchSettled
                : localSearchSettled);

        if (canOfferCustom) {
            list = [
                {
                    id: `${CUSTOM_ID_PREFIX}${trimmed}`,
                    label: trimmed,
                    description: 'Use custom value',
                },
            ];
        }

        return list;
    }, [
        allowCustomValue,
        loading,
        query,
        searchError,
        searchUrl,
        settledQuery,
        sourceOptions,
    ]);

    const selectedOption = useMemo(() => {
        const fromLists =
            findOption(filtered, selected) ??
            findOption(sourceOptions, selected) ??
            findOption(options, selected) ??
            optionCache[selected] ??
            null;
        return fromLists;
    }, [filtered, sourceOptions, options, selected, optionCache]);

    const selectedLabel = selectedOption?.label ?? resolvedPlaceholder;

    const mergeCache = useCallback(
        (next: ComboboxOption[]) => {
            setOptionCache((prev) => {
                const merged = { ...prev };
                for (const option of next) {
                    if (!isCustomOptionId(option.id) || allowCustomValue) {
                        merged[option.id] = option;
                    }
                }
                if (selected !== '') {
                    const current = findOption(next, selected);
                    if (current) {
                        merged[selected] = current;
                    }
                }
                return merged;
            });
        },
        [allowCustomValue, selected],
    );

    useEffect(() => {
        mergeCache(options);
    }, [options, mergeCache]);

    useEffect(() => {
        if (!open || !searchUrl || !buildSearchParams || !mapResponse) {
            return;
        }

        const controller = new AbortController();
        const seq = ++requestSeqRef.current;
        const timer = window.setTimeout(() => {
            const params = buildSearchParams(query, selected);
            const requestQuery = query.trim();

            setLoading(true);
            setSearchError(null);
            setSettledQuery(null);

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
                        throw new Error('Search failed.');
                    }
                    const json: unknown = await response.json();
                    if (seq !== requestSeqRef.current) {
                        return;
                    }
                    const next = mapResponse(json);
                    setRemoteOptions(next);
                    mergeCache(next);
                    setSettledQuery(requestQuery);
                })
                .catch((fetchError: unknown) => {
                    if (
                        fetchError instanceof DOMException &&
                        fetchError.name === 'AbortError'
                    ) {
                        return;
                    }
                    if (seq !== requestSeqRef.current) {
                        return;
                    }
                    // Failed requests must not be treated as empty Master results.
                    setSearchError('Unable to search.');
                    setSettledQuery(null);
                })
                .finally(() => {
                    if (seq === requestSeqRef.current) {
                        setLoading(false);
                    }
                });
        }, 200);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [
        buildSearchParams,
        mapResponse,
        mergeCache,
        open,
        query,
        searchUrl,
        selected,
    ]);

    useEffect(() => {
        if (!open) {
            setRemoteOptions(null);
            setQuery('');
            setSearchError(null);
            setSettledQuery(null);
            setLoading(false);
            requestSeqRef.current += 1;
        }
    }, [open]);

    useLayoutEffect(() => {
        if (!open) {
            setPanelPosition(null);
            return;
        }

        const update = () => {
            setPanelPosition(computePanelPosition(triggerRef.current));
        };
        update();

        window.addEventListener('resize', update);
        window.addEventListener('scroll', update, true);
        return () => {
            window.removeEventListener('resize', update);
            window.removeEventListener('scroll', update, true);
        };
    }, [open, filtered.length, loading]);

    useEffect(() => {
        if (!open) {
            return;
        }
        setHighlightedIndex(0);
        const timer = window.setTimeout(() => {
            searchRef.current?.focus();
        }, 0);
        return () => window.clearTimeout(timer);
    }, [open]);

    useEffect(() => {
        if (highlightedIndex >= filtered.length) {
            setHighlightedIndex(filtered.length > 0 ? filtered.length - 1 : 0);
        }
    }, [filtered.length, highlightedIndex]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const handlePointer = (event: MouseEvent) => {
            const target = event.target as Node;
            if (
                rootRef.current?.contains(target) ||
                panelRef.current?.contains(target)
            ) {
                return;
            }
            setOpen(false);
        };
        document.addEventListener('mousedown', handlePointer);
        return () => document.removeEventListener('mousedown', handlePointer);
    }, [open]);

    const applySelection = useCallback(
        (nextId: string, option: ComboboxOption | null) => {
            if (
                nextId !== '' &&
                !allowCustomValue &&
                (isCustomOptionId(nextId) ||
                    option?.description === 'Use custom value')
            ) {
                return;
            }

            if (value === undefined) {
                setInternalValue(nextId);
            }
            if (option && (!isCustomOptionId(option.id) || allowCustomValue)) {
                setOptionCache((prev) => ({
                    ...prev,
                    [option.id]: option,
                }));
            }
            onValueChange?.(nextId, option);
            setOpen(false);
            setQuery('');
        },
        [allowCustomValue, onValueChange, value],
    );

    const handleNativeSelectChange = (
        event: React.ChangeEvent<HTMLSelectElement>,
    ) => {
        const nextId = event.target.value;
        if (nextId !== '' && !allowCustomValue && isCustomOptionId(nextId)) {
            return;
        }
        const option =
            findOption(filtered, nextId) ??
            findOption(sourceOptions, nextId) ??
            findOption(options, nextId) ??
            optionCache[nextId] ??
            null;
        if (value === undefined) {
            setInternalValue(nextId);
        }
        onValueChange?.(nextId, option);
    };

    const selectOptions = useMemo(() => {
        const byId = new Map<string, ComboboxOption>();
        for (const option of options) {
            if (!isCustomOptionId(option.id) || allowCustomValue) {
                byId.set(option.id, option);
            }
        }
        for (const option of sourceOptions) {
            if (!isCustomOptionId(option.id) || allowCustomValue) {
                byId.set(option.id, option);
            }
        }
        for (const cached of Object.values(optionCache)) {
            if (!isCustomOptionId(cached.id) || allowCustomValue) {
                byId.set(cached.id, cached);
            }
        }
        if (selected !== '' && !byId.has(selected) && selectedOption) {
            if (!isCustomOptionId(selected) || allowCustomValue) {
                byId.set(selected, selectedOption);
            }
        }
        return [...byId.values()];
    }, [
        allowCustomValue,
        optionCache,
        options,
        selected,
        selectedOption,
        sourceOptions,
    ]);

    const onTriggerKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (disabled) {
            return;
        }
        if (
            event.key === 'ArrowDown' ||
            event.key === 'Enter' ||
            event.key === ' '
        ) {
            event.preventDefault();
            setOpen(true);
            return;
        }
        if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    const onPanelKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            setQuery('');
            return;
        }
        if (
            event.key.length === 1 &&
            !event.ctrlKey &&
            !event.metaKey &&
            !event.altKey &&
            event.target !== searchRef.current
        ) {
            event.preventDefault();
            searchRef.current?.focus();
            setQuery((current) => current + event.key);
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlightedIndex((index) =>
                filtered.length === 0
                    ? 0
                    : Math.min(index + 1, filtered.length - 1),
            );
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlightedIndex((index) => Math.max(index - 1, 0));
            return;
        }
        if (event.key === 'Enter' || event.key === 'Tab') {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
            const option = filtered[highlightedIndex];
            // Enter/Tab only commit a listed option. Never accept raw search text
            // when no option is highlighted (avoids incomplete custom selection).
            if (!option) {
                return;
            }
            if (event.key === 'Tab') {
                event.preventDefault();
            }
            applySelection(option.id, option);
        }
    };

    const addActionControl =
        addAction && (addAction.href || addAction.onClick) ? (
            addAction.href ? (
                <a
                    href={addAction.href}
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                    {addAction.label}
                </a>
            ) : (
                <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="h-auto px-0 text-sm"
                    onClick={addAction.onClick}
                >
                    {addAction.label}
                </Button>
            )
        ) : null;

    const panel =
        open && panelPosition !== null
            ? createPortal(
                  <div
                      ref={panelRef}
                      data-searchable-combobox-panel=""
                      className="pointer-events-auto fixed z-[200] rounded-md border border-input bg-popover text-popover-foreground shadow-md"
                      style={{
                          top: panelPosition.top,
                          left: panelPosition.left,
                          width: panelPosition.width,
                          maxHeight: panelPosition.maxHeight,
                      }}
                      onPointerDown={(event) => event.stopPropagation()}
                      onMouseDown={(event) => event.stopPropagation()}
                      onKeyDown={onPanelKeyDown}
                  >
                      <div className="border-b border-input p-2">
                          <Input
                              ref={searchRef}
                              id={panelSearchId}
                              type="search"
                              value={query}
                              onChange={(event) => setQuery(event.target.value)}
                              placeholder={`Search ${(label ?? 'option').toLowerCase()}…`}
                              autoComplete="off"
                              disabled={disabled}
                              aria-controls={listboxId}
                              aria-autocomplete="list"
                          />
                      </div>
                      <div
                          id={listboxId}
                          role="listbox"
                          aria-labelledby={`${id}-label`}
                          className="overflow-y-auto p-1"
                          style={{
                              maxHeight: Math.max(
                                  80,
                                  panelPosition.maxHeight - 52,
                              ),
                          }}
                      >
                          {loading ? (
                              <p className="px-2 py-3 text-sm text-muted-foreground">
                                  Searching…
                              </p>
                          ) : null}
                          {!loading && filtered.length === 0 ? (
                              <p className="px-2 py-3 text-sm text-muted-foreground">
                                  {emptyMessage}
                              </p>
                          ) : null}
                          {!loading
                              ? filtered.map((option, index) => (
                                    <div
                                        key={option.id}
                                        role="option"
                                        aria-selected={option.id === selected}
                                        id={`${listboxId}-option-${option.id}`}
                                        className={cn(
                                            'cursor-pointer rounded-sm px-2 py-1.5 text-sm outline-none',
                                            index === highlightedIndex &&
                                                'bg-accent text-accent-foreground',
                                            option.id === selected &&
                                                'font-medium',
                                        )}
                                        onMouseEnter={() =>
                                            setHighlightedIndex(index)
                                        }
                                        onMouseDown={(event) => {
                                            event.preventDefault();
                                        }}
                                        onClick={() =>
                                            applySelection(option.id, option)
                                        }
                                    >
                                        <span className="block truncate">
                                            {option.label}
                                        </span>
                                        {option.description ? (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {option.description}
                                            </span>
                                        ) : null}
                                    </div>
                                ))
                              : null}
                      </div>
                      {searchError ? (
                          <p
                              className="border-t border-input px-2 py-2 text-xs text-destructive"
                              role="alert"
                          >
                              {searchError}
                          </p>
                      ) : null}
                  </div>,
                  document.body,
              )
            : null;

    if (showEmptyState) {
        return (
            <div className={cn('space-y-2', className)}>
                <div className="flex items-center justify-between gap-2">
                    <Label htmlFor={id} required={required}>
                        {label}
                    </Label>
                    {addActionControl}
                </div>
                <div className="space-y-2 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                    <p>{emptyMessage}</p>
                </div>
                <select
                    id={id}
                    name={name}
                    required={required}
                    disabled
                    className="sr-only"
                    value=""
                    tabIndex={-1}
                    aria-hidden="true"
                >
                    <option value="">{resolvedPlaceholder}</option>
                </select>
                {error ? (
                    <p className="text-sm text-destructive" role="alert">
                        {error}
                    </p>
                ) : null}
            </div>
        );
    }

    return (
        <div className={cn('space-y-2', className)} ref={rootRef}>
            <div className="flex items-center justify-between gap-2">
                <Label htmlFor={id} required={required}>
                    {label}
                </Label>
                {addActionControl}
            </div>
            <div className="relative">
                <div className="flex gap-1">
                    <button
                        type="button"
                        ref={triggerRef}
                        role="combobox"
                        id={`${id}-trigger`}
                        disabled={disabled}
                        aria-expanded={open}
                        aria-controls={listboxId}
                        aria-haspopup="listbox"
                        aria-labelledby={`${id}-label`}
                        aria-required={required ? 'true' : undefined}
                        className={cn(
                            'flex h-9 min-w-0 flex-1 items-center justify-between gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                            !selectedOption && 'text-muted-foreground',
                        )}
                        onClick={() => {
                            if (!disabled) {
                                setOpen((wasOpen) => !wasOpen);
                            }
                        }}
                        onKeyDown={onTriggerKeyDown}
                    >
                        <span className="truncate text-left">
                            {selected !== ''
                                ? selectedLabel
                                : resolvedPlaceholder}
                        </span>
                        <ChevronDown
                            className={cn(
                                'size-4 shrink-0 opacity-50 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    </button>
                    {clearable && selected !== '' && !disabled ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-9 shrink-0"
                            aria-label={`Clear ${label}`}
                            onClick={() => {
                                applySelection('', null);
                            }}
                        >
                            <X className="size-4" />
                        </Button>
                    ) : null}
                </div>

                {panel}

                <select
                    id={id}
                    name={name}
                    required={required}
                    disabled={disabled}
                    className="sr-only"
                    tabIndex={-1}
                    aria-hidden="true"
                    value={selected}
                    onChange={handleNativeSelectChange}
                >
                    <option value="" disabled={required}>
                        {resolvedPlaceholder}
                    </option>
                    {selectOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
            <span id={`${id}-label`} className="sr-only">
                {label}
            </span>
            {error ? (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
