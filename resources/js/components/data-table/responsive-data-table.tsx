import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import ShellEmptyState from '@/components/shell-empty-state';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { priorityVisibilityClass } from './priority-visibility';
import type { DataTableProps } from './types';

/**
 * Priority-based responsive table: P0 always visible; lower priorities hide
 * as the viewport shrinks. Hidden attributes remain available via row expand.
 * Avoids horizontal scrolling for ordinary management tables.
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

    const hasDetail = useMemo(
        () =>
            detailFields !== undefined ||
            columns.some((column) => column.priority > 0),
        [columns, detailFields],
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
                        {hasDetail ? (
                            <th className="w-10 px-2 py-2 font-medium">
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
                            .filter((column) => column.priority > 0)
                            .map((column) => ({
                                id: column.id,
                                priority: column.priority,
                                label: column.header,
                                value: column.cell(row),
                            }));

                        return (
                            <Fragment key={id}>
                                <tr className="border-b border-border last:border-0">
                                    {hasDetail ? (
                                        <td className="px-1 py-2">
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
                                {hasDetail && isOpen ? (
                                    <tr className="border-b border-border bg-muted/20">
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
                                                                field.priority ===
                                                                    1 &&
                                                                    'sm:hidden',
                                                                field.priority ===
                                                                    2 &&
                                                                    'md:hidden',
                                                                field.priority ===
                                                                    3 &&
                                                                    'xl:hidden',
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
