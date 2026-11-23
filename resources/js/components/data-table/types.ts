import type { ReactNode } from 'react';

/**
 * Column priority for responsive visibility.
 * P0 always visible; higher numbers hide first as width shrinks.
 */
export type ColumnPriority = 0 | 1 | 2 | 3;

export type DataTableColumn<T> = {
    id: string;
    header: ReactNode;
    /** Cell renderer for a row. */
    cell: (row: T) => ReactNode;
    priority: ColumnPriority;
    /** Optional class for th/td (alignment, mono, etc.). */
    className?: string;
    /** When true, column stays visible in the expandable detail panel even if shown in the table. */
    alwaysInDetail?: boolean;
    /** Hide from expand-detail when already visible at current breakpoint (default true). */
    omitFromDetailWhenVisible?: boolean;
};

export type DataTableAction<T> = {
    id: string;
    label: string;
    icon?: ReactNode;
    onSelect?: (row: T) => void;
    href?: string | ((row: T) => string | undefined);
    disabled?: boolean | ((row: T) => boolean);
    visible?: boolean | ((row: T) => boolean);
    variant?: 'default' | 'destructive';
};

export type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    /** Primary actions rendered as icon buttons (desktop) / menu items (compact). */
    actions?: (row: T) => ReactNode;
    /** Extra fields shown only in the expand/detail panel. */
    detailFields?: (row: T) => Array<{ label: string; value: ReactNode }>;
    emptyTitle?: string;
    emptyDescription?: string;
    emptyAction?: ReactNode;
    className?: string;
    /** Accessible name for the table. */
    caption?: string;
};
