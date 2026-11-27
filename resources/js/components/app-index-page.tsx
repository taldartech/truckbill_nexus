import type { FormEvent, ReactNode } from 'react';
import { AppCardHeader } from '@/components/app-card-header';
import { cn } from '@/lib/utils';

export function AppIndexPage({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'app-index-page app-index-page--standard app-index-page--register',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function AppIndexListCard({
    title,
    toolbar,
    children,
    className,
}: {
    title: ReactNode;
    toolbar?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'app-index-list-card overflow-hidden rounded-xl border border-[#e5e7eb] bg-white shadow-[0_2px_8px_rgba(0,0,0,0.04)]',
                className,
            )}
        >
            <AppCardHeader
                className="app-index-list-card__header items-center bg-[linear-gradient(135deg,#2fb36f_0%,#23965c_100%)] px-4 py-2.5 text-white"
                title={title}
                titleClassName="text-[0.95rem] font-semibold tracking-normal text-white"
                actions={toolbar}
                actionsClassName="app-index-card-toolbar gap-2"
            />
            <div className="app-index-list-card__body min-w-0 p-0">
                {children}
            </div>
        </div>
    );
}

export function AppIndexFilters({
    children,
    onSubmit,
}: {
    children: ReactNode;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    return (
        <form
            className="app-index-filters flex flex-wrap items-end gap-3 border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-3"
            onSubmit={onSubmit}
        >
            {children}
        </form>
    );
}
