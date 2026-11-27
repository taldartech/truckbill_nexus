import { useCallback, useEffect, useMemo, useState } from 'react';

type Identified = { id: string };

/**
 * Register row selection that survives pagination and filter visits (session-scoped).
 * Rows are kept as snapshots so actions can validate selections made on other pages.
 */
export function useCrossPageSelection<Row extends Identified>(
    storageKey: string,
) {
    const [selected, setSelected] = useState<Record<string, Row>>(() => {
        if (typeof window === 'undefined') {
            return {};
        }
        try {
            const stored = window.sessionStorage.getItem(storageKey);

            return stored ? (JSON.parse(stored) as Record<string, Row>) : {};
        } catch {
            return {};
        }
    });

    useEffect(() => {
        window.sessionStorage.setItem(storageKey, JSON.stringify(selected));
    }, [selected, storageKey]);

    const toggle = useCallback((row: Row) => {
        setSelected((current) => {
            const next = { ...current };
            if (next[row.id]) {
                delete next[row.id];
            } else {
                next[row.id] = row;
            }

            return next;
        });
    }, []);

    const setMany = useCallback((rows: Row[], checked: boolean) => {
        setSelected((current) => {
            const next = { ...current };
            for (const row of rows) {
                if (checked) {
                    next[row.id] = row;
                } else {
                    delete next[row.id];
                }
            }

            return next;
        });
    }, []);

    const remove = useCallback((ids: string[]) => {
        setSelected((current) => {
            const next = { ...current };
            for (const id of ids) {
                delete next[id];
            }

            return next;
        });
    }, []);

    const clear = useCallback(() => setSelected({}), []);

    const rows = useMemo(() => Object.values(selected), [selected]);

    return {
        rows,
        ids: useMemo(() => rows.map((row) => row.id), [rows]),
        count: rows.length,
        isSelected: (id: string) => Boolean(selected[id]),
        toggle,
        setMany,
        remove,
        clear,
    };
}
