import { cn } from '@/lib/utils';

type WorkspaceStatusBadgeProps = {
    status: string;
    className?: string;
};

const toneFor = (
    status: string,
): { dot: string; wrap: string; label: string } => {
    const normalized = status.trim().toLowerCase().replace(/_/g, ' ');

    if (
        [
            'active',
            'paid',
            'completed',
            'converted',
            'resolved',
            'delivered',
            'approved',
            'selected',
        ].some((token) => normalized.includes(token))
    ) {
        return {
            wrap: 'border-[#2FB36F]/30 bg-[#2FB36F]/10 text-[#1f6b45]',
            dot: 'bg-[#2FB36F]',
            label: status,
        };
    }

    if (
        [
            'pending',
            'draft',
            'partial',
            'in progress',
            'contacted',
            'quoted',
            'under review',
            'open',
        ].some((token) => normalized.includes(token))
    ) {
        return {
            wrap: 'border-[#FFC107]/40 bg-[#FFC107]/15 text-[#8a6a00]',
            dot: 'bg-[#FFC107]',
            label: status,
        };
    }

    if (
        [
            'cancelled',
            'rejected',
            'failed',
            'overdue',
            'inactive',
            'suspended',
            'disqualified',
            'lost',
            'closed',
        ].some((token) => normalized.includes(token))
    ) {
        return {
            wrap: 'border-destructive/30 bg-destructive/10 text-destructive',
            dot: 'bg-destructive',
            label: status,
        };
    }

    if (
        ['new', 'info', 'quoted', 'quotations received'].some((token) =>
            normalized.includes(token),
        )
    ) {
        return {
            wrap: 'border-[#2D8CFF]/30 bg-[#2D8CFF]/10 text-[#1a5fbf]',
            dot: 'bg-[#2D8CFF]',
            label: status,
        };
    }

    return {
        wrap: 'border-border bg-muted/60 text-foreground',
        dot: 'bg-foreground/60',
        label: status,
    };
};

/**
 * Status must not rely on colour alone (Design.md §13).
 */
export function WorkspaceStatusBadge({
    status,
    className,
}: WorkspaceStatusBadgeProps) {
    const tone = toneFor(status);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium capitalize',
                tone.wrap,
                className,
            )}
        >
            <span
                aria-hidden
                className={cn('size-1.5 rounded-full', tone.dot)}
            />
            <span>{tone.label.replace(/_/g, ' ')}</span>
        </span>
    );
}
