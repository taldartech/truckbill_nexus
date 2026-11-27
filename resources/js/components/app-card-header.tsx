import type { ElementType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type AppCardHeaderProps = {
    title?: ReactNode;
    /** Heading element for the title (page headers use h1). */
    titleAs?: ElementType;
    titleClassName?: string;
    /** Status / role badges rendered inline after the title. */
    badges?: ReactNode;
    /** Secondary information rendered below the title. */
    meta?: ReactNode;
    metaClassName?: string;
    /** Authorized actions — right-aligned, icon-only `AppIconAction`s preferred. */
    actions?: ReactNode;
    actionsClassName?: string;
    /** Leading content inside the title column (e.g. brand accent bar). */
    leading?: ReactNode;
    className?: string;
};

/**
 * Standard page / card / record header (Rules §26): title and secondary
 * information on the left, authorized actions right-aligned, wrapping on
 * narrow screens without overlapping the title.
 */
export function AppCardHeader({
    title,
    titleAs: Title = 'h2',
    titleClassName,
    badges,
    meta,
    metaClassName,
    actions,
    actionsClassName,
    leading,
    className,
}: AppCardHeaderProps) {
    return (
        <div
            className={cn(
                'app-card-header flex flex-wrap items-start justify-between gap-x-3 gap-y-2',
                className,
            )}
        >
            <div className="app-card-header__main min-w-0 flex-1 space-y-1">
                {leading}
                {title || badges ? (
                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        {title ? (
                            <Title
                                className={cn(
                                    'app-card-header__title min-w-0 break-words',
                                    titleClassName,
                                )}
                            >
                                {title}
                            </Title>
                        ) : null}
                        {badges}
                    </div>
                ) : null}
                {meta ? (
                    <div
                        className={cn(
                            'app-card-header__meta text-sm text-muted-foreground',
                            metaClassName,
                        )}
                    >
                        {meta}
                    </div>
                ) : null}
            </div>
            {actions ? (
                <div
                    className={cn(
                        'app-card-header__actions ml-auto flex shrink-0 flex-wrap items-center justify-end gap-1.5',
                        actionsClassName,
                    )}
                >
                    {actions}
                </div>
            ) : null}
        </div>
    );
}
