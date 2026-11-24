import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    expandControlVisibilityClass,
    maxColumnPriority,
    priorityVisibilityClass,
} from './priority-visibility';
import type { DataTableProps } from './types';

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
            <table className="w-full table-fixed text-left text-sm">
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
                                className={cn(
                                    'px-3 py-2 font-medium',
                                    priorityVisibilityClass(column.priority),
                                    column.className,
                                    column.priority === 0 && 'w-[28%]',
                                )}
                            >
                                {column.header}
                            </th>
                        ))}
                        {actions ? (
                            <th className="w-[7.5rem] px-3 py-2 text-right font-medium sm:w-[11rem]">
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
                                alwaysInDetail: column.alwaysInDetail === true,
                                omitWhenVisible:
                                    column.omitFromDetailWhenVisible !== false,
                                label: column.header,
                                value: column.cell(row),
                            }));

                        const hasDetailContent =
                            extras.length > 0 || hiddenColumnDetails.length > 0;

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
                                                    onClick={() => toggle(id)}
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
                                            className={cn(
                                                'truncate px-3 py-3',
                                                priorityVisibilityClass(
                                                    column.priority,
                                                ),
                                                column.className,
                                                column.priority === 0 &&
                                                    'font-medium',
                                            )}
                                        >
                                            {column.cell(row)}
                                        </td>
                                    ))}
                                    {actions ? (
                                        <td className="px-2 py-2 text-right">
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
                                            className="px-4 py-3"
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
                                                                {field.label}
                                                            </dt>
                                                            <dd className="mt-0.5 text-sm break-words">
                                                                {field.value}
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
    );
}
