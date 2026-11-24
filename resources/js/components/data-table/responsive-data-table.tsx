import { ChevronDown, ChevronRight } from 'lucide-react';
import {
    Fragment,
    useMemo,
    useState,
    type CSSProperties,
    type ReactNode,
} from 'react';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    expandControlVisibilityClass,
    maxColumnPriority,
    priorityVisibilityClass,
} from './priority-visibility';
import type { DataTableColumn, DataTableProps } from './types';

function columnSizingStyle<T>(column: DataTableColumn<T>): CSSProperties {
    const style: CSSProperties = {};

    if (column.width !== undefined) {
        style.width = column.width;
    }

    if (column.minWidth !== undefined) {
        style.minWidth = column.minWidth;
    }

    if (column.maxWidth !== undefined) {
        style.maxWidth = column.maxWidth;
    }

    return style;
}

function alignmentClass(align?: DataTableColumn<never>['align']): string {
    if (align === 'center') {
        return 'text-center';
    }

    if (align === 'right') {
        return 'text-right';
    }

    return 'text-left';
}

function isShortIdColumn<T>(column: DataTableColumn<T>): boolean {
    const id = column.id.toLowerCase();

    if (/(_number|_no|_id|_code|_ref)$/.test(id)) {
        return true;
    }

    return ['lr_number', 'ref', 'code', 'number'].includes(id);
}

function shouldTruncateCell<T>(column: DataTableColumn<T>): boolean {
    if (column.wrap === true) {
        return false;
    }

    return (
        isShortIdColumn(column) ||
        column.className?.includes('truncate') === true
    );
}

function renderCellContent<T>(column: DataTableColumn<T>, row: T): ReactNode {
    const content = column.cell(row);

    if (column.wrap === true) {
        return <span className="break-words whitespace-normal">{content}</span>;
    }

    if (shouldTruncateCell(column)) {
        const title = typeof content === 'string' ? content : undefined;

        return (
            <span className="block min-w-0 truncate" title={title}>
                {content}
            </span>
        );
    }

    return content;
}

/**
 * Priority-based responsive table: P0 always visible; lower priorities hide
 * as the viewport shrinks. Hidden attributes remain available via row expand.
 * Expand controls appear only when they can reveal content at the viewport.
 */
export function ResponsiveDataTable<T>({
    columns,
    rows,
    rowKey,
    actions,
    detailFields,
    emptyTitle = 'No records',
    emptyDescription = 'Nothing to show yet.',
    emptyAction,
    className,
    caption,
}: DataTableProps<T>) {
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    const maxPriority = useMemo(
        () => maxColumnPriority(columns.map((column) => column.priority)),
        [columns],
    );

    const hasAlwaysOnDetailFields = detailFields !== undefined;

    const hasExpandableColumns = maxPriority > 0 || hasAlwaysOnDetailFields;

    const expandVisibilityClass = expandControlVisibilityClass(
        maxPriority,
        hasAlwaysOnDetailFields,
    );

    const toggle = (id: string) => {
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    if (rows.length === 0) {
        return (
            <ShellEmptyState
                title={emptyTitle}
                description={emptyDescription}
                action={emptyAction}
            />
        );
    }

    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-border',
                className,
            )}
        >
            <div className="overflow-x-auto">
                <table className="w-full min-w-[56rem] table-auto text-left text-sm">
                    {caption ? (
                        <caption className="sr-only">{caption}</caption>
                    ) : null}
                    <thead className="border-b border-border bg-muted/40 text-xs uppercase">
                        <tr>
                            {hasExpandableColumns ? (
                                <th
                                    className={cn(
                                        'w-10 px-2 py-2 font-medium',
                                        expandVisibilityClass,
                                    )}
                                >
                                    <span className="sr-only">Details</span>
                                </th>
                            ) : null}
                            {columns.map((column) => (
                                <th
                                    key={column.id}
                                    style={columnSizingStyle(column)}
                                    className={cn(
                                        'px-3 py-2 font-medium',
                                        column.wrap === true
                                            ? 'break-words whitespace-normal'
                                            : 'whitespace-nowrap',
                                        alignmentClass(column.align),
                                        priorityVisibilityClass(
                                            column.priority,
                                        ),
                                        column.className,
                                    )}
                                >
                                    {column.header}
                                </th>
                            ))}
                            {actions ? (
                                <th className="min-w-[10rem] px-3 py-2 text-right font-medium whitespace-nowrap">
                                    Actions
                                </th>
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const id = rowKey(row);
                            const isOpen = expanded.has(id);
                            const extras = detailFields?.(row) ?? [];
                            const hiddenColumnDetails = columns
                                .filter(
                                    (column) =>
                                        column.priority > 0 ||
                                        column.alwaysInDetail === true,
                                )
                                .map((column) => ({
                                    id: column.id,
                                    priority: column.priority,
                                    alwaysInDetail:
                                        column.alwaysInDetail === true,
                                    omitWhenVisible:
                                        column.omitFromDetailWhenVisible !==
                                        false,
                                    label: column.header,
                                    value: column.cell(row),
                                }));

                            const hasDetailContent =
                                extras.length > 0 ||
                                hiddenColumnDetails.length > 0;

                            return (
                                <Fragment key={id}>
                                    <tr className="border-b border-border last:border-0">
                                        {hasExpandableColumns ? (
                                            <td
                                                className={cn(
                                                    'px-1 py-2',
                                                    expandVisibilityClass,
                                                )}
                                            >
                                                {hasDetailContent ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-expanded={isOpen}
                                                        aria-label={
                                                            isOpen
                                                                ? 'Hide row details'
                                                                : 'Show row details'
                                                        }
                                                        onClick={() =>
                                                            toggle(id)
                                                        }
                                                    >
                                                        {isOpen ? (
                                                            <ChevronDown className="size-4" />
                                                        ) : (
                                                            <ChevronRight className="size-4" />
                                                        )}
                                                    </Button>
                                                ) : null}
                                            </td>
                                        ) : null}
                                        {columns.map((column) => (
                                            <td
                                                key={column.id}
                                                style={columnSizingStyle(
                                                    column,
                                                )}
                                                className={cn(
                                                    'px-3 py-2',
                                                    alignmentClass(
                                                        column.align,
                                                    ),
                                                    priorityVisibilityClass(
                                                        column.priority,
                                                    ),
                                                    column.className,
                                                    column.priority === 0 &&
                                                        'font-medium',
                                                )}
                                            >
                                                {renderCellContent(column, row)}
                                            </td>
                                        ))}
                                        {actions ? (
                                            <td className="min-w-[10rem] px-2 py-2 text-right whitespace-nowrap">
                                                <div className="flex flex-wrap items-center justify-end gap-1">
                                                    {actions(row)}
                                                </div>
                                            </td>
                                        ) : null}
                                    </tr>
                                    {hasExpandableColumns &&
                                    hasDetailContent &&
                                    isOpen ? (
                                        <tr
                                            className={cn(
                                                'border-b border-border bg-muted/20',
                                                expandVisibilityClass,
                                            )}
                                        >
                                            <td
                                                colSpan={
                                                    columns.length +
                                                    (actions ? 1 : 0) +
                                                    1
                                                }
                                                className="px-4 py-2"
                                            >
                                                <dl className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                    {hiddenColumnDetails.map(
                                                        (field) => (
                                                            <div
                                                                key={field.id}
                                                                className={cn(
                                                                    field.alwaysInDetail ||
                                                                        !field.omitWhenVisible
                                                                        ? undefined
                                                                        : field.priority ===
                                                                            1
                                                                          ? 'sm:hidden'
                                                                          : field.priority ===
                                                                              2
                                                                            ? 'md:hidden'
                                                                            : field.priority ===
                                                                                3
                                                                              ? 'xl:hidden'
                                                                              : undefined,
                                                                )}
                                                            >
                                                                <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                                    {
                                                                        field.label
                                                                    }
                                                                </dt>
                                                                <dd className="mt-0.5 text-sm break-words">
                                                                    {
                                                                        field.value
                                                                    }
                                                                </dd>
                                                            </div>
                                                        ),
                                                    )}
                                                    {extras.map((field) => (
                                                        <div
                                                            key={String(
                                                                field.label,
                                                            )}
                                                        >
                                                            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                                {field.label}
                                                            </dt>
                                                            <dd className="mt-0.5 text-sm break-words">
                                                                {field.value}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            </td>
                                        </tr>
                                    ) : null}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
