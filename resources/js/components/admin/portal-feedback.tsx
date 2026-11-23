import type { ReactNode } from 'react';

type PortalFlashBannerProps = {
    message?: string | null;
};

export function PortalFlashBanner({ message }: PortalFlashBannerProps) {
    if (!message) {
        return null;
    }

    return (
        <p
            role="status"
            className="rounded-xl border border-[#2FB36F]/30 bg-[#2FB36F]/10 px-4 py-3 text-sm text-foreground"
        >
            {message}
        </p>
    );
}

type PortalErrorListProps = {
    errors?: Record<string, string | string[] | undefined>;
    keys?: string[];
};

export function PortalErrorList({ errors, keys }: PortalErrorListProps) {
    if (!errors) {
        return null;
    }

    const messages = keys
        ? keys
              .map((key) => errors[key])
              .filter(Boolean)
              .flatMap((value) =>
                  Array.isArray(value) ? value : value ? [value] : [],
              )
        : Object.values(errors).filter(
              (value): value is string =>
                  typeof value === 'string' && value.length > 0,
          );

    if (messages.length === 0) {
        return null;
    }

    return (
        <div className="space-y-1" aria-live="polite">
            {messages.map((message) => (
                <p
                    key={message}
                    className="text-sm text-destructive"
                    role="alert"
                >
                    {message}
                </p>
            ))}
        </div>
    );
}

type DetailItemProps = {
    label: string;
    value: ReactNode;
    className?: string;
};

export function DetailItem({ label, value, className }: DetailItemProps) {
    return (
        <div className={className}>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1 text-sm text-foreground">{value}</dd>
        </div>
    );
}
