import { router } from '@inertiajs/react';
import {
    ChevronFirst,
    ChevronLast,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type TablePaginationMeta = {
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
};

export type TablePaginationProps = {
    baseUrl: string;
    meta: TablePaginationMeta;
    query?: Record<string, string | number | undefined | null>;
    perPageOptions?: number[];
};

const DEFAULT_PER_PAGE_OPTIONS = [10, 25, 50, 100];

function cleanQuery(
    query: Record<string, string | number | undefined | null>,
    page: number,
    perPage: number,
): Record<string, string | number> {
    const merged: Record<string, string | number | undefined | null> = {
        ...query,
        page,
        per_page: perPage,
    };

    const result: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(merged)) {
        if (value === undefined || value === null || value === '') {
            continue;
        }
        result[key] = value;
    }

    return result;
}

export function buildVisiblePages(
    currentPage: number,
    lastPage: number,
): Array<number | 'ellipsis'> {
    if (lastPage <= 1) {
        return lastPage === 1 ? [1] : [];
    }

    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    const pages = new Set<number>([1, lastPage]);
    for (let page = currentPage - 1; page <= currentPage + 1; page += 1) {
        if (page >= 1 && page <= lastPage) {
            pages.add(page);
        }
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const visible: Array<number | 'ellipsis'> = [];

    for (let index = 0; index < sorted.length; index += 1) {
        const page = sorted[index];
        const previous = sorted[index - 1];
        if (index > 0 && page - previous > 1) {
            visible.push('ellipsis');
        }
        visible.push(page);
    }

    return visible;
}

function showingRange(meta: TablePaginationMeta): string {
    const { total, current_page, last_page } = meta;
    if (total === 0 || last_page === 0) {
        return 'No records to show';
    }

    const perPage = meta.per_page ?? 15;
    const from = (current_page - 1) * perPage + 1;
    const to = Math.min(current_page * perPage, total);

    return `Showing ${from}–${to} of ${total} records`;
}

export function TablePagination({
    baseUrl,
    meta,
    query = {},
    perPageOptions = DEFAULT_PER_PAGE_OPTIONS,
}: TablePaginationProps) {
    const perPage = meta.per_page ?? perPageOptions[0] ?? 15;
    const visiblePages = useMemo(
        () => buildVisiblePages(meta.current_page, meta.last_page),
        [meta.current_page, meta.last_page],
    );

    const visit = (page: number, nextPerPage?: number) => {
        const resolvedPerPage = nextPerPage ?? perPage;
        router.get(baseUrl, cleanQuery(query, page, resolvedPerPage), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const atFirst = meta.current_page <= 1;
    const atLast = meta.current_page >= meta.last_page || meta.last_page === 0;

    return (
        <nav
            className="flex flex-wrap items-center justify-between gap-4 border-t border-border pt-4"
            aria-label="Table pagination"
        >
            <p className="text-sm text-muted-foreground">
                {showingRange(meta)}
            </p>

            <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-2">
                    <Label
                        htmlFor="table-pagination-per-page"
                        className="text-xs text-muted-foreground"
                    >
                        Rows per page
                    </Label>
                    <select
                        id="table-pagination-per-page"
                        className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        value={perPage}
                        onChange={(event) =>
                            visit(1, Number(event.target.value))
                        }
                        aria-label="Rows per page"
                    >
                        {perPageOptions.map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex flex-wrap items-center gap-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        disabled={atFirst}
                        onClick={() => visit(1)}
                        aria-label="First page"
                    >
                        <ChevronFirst className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        disabled={atFirst}
                        onClick={() => visit(meta.current_page - 1)}
                        aria-label="Previous page"
                    >
                        <ChevronLeft className="size-4" />
                    </Button>

                    {visiblePages.map((page, index) =>
                        page === 'ellipsis' ? (
                            <span
                                key={`ellipsis-${index}`}
                                className="px-2 text-sm text-muted-foreground"
                                aria-hidden
                            >
                                …
                            </span>
                        ) : (
                            <Button
                                key={page}
                                type="button"
                                variant={
                                    page === meta.current_page
                                        ? 'secondary'
                                        : 'outline'
                                }
                                size="sm"
                                className={cn(
                                    'min-w-8 px-2',
                                    page === meta.current_page &&
                                        'pointer-events-none',
                                )}
                                onClick={() => visit(page)}
                                aria-label={`Page ${page}`}
                                aria-current={
                                    page === meta.current_page
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {page}
                            </Button>
                        ),
                    )}

                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        disabled={atLast}
                        onClick={() => visit(meta.current_page + 1)}
                        aria-label="Next page"
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        disabled={atLast}
                        onClick={() => visit(meta.last_page)}
                        aria-label="Last page"
                    >
                        <ChevronLast className="size-4" />
                    </Button>
                </div>
            </div>
        </nav>
    );
}
