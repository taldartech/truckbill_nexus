import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type MetricTone = 'green' | 'blue' | 'amber' | 'neutral' | 'red';

const toneClass: Record<MetricTone, string> = {
    green: 'from-[#2FB36F]/15 to-[#2FB36F]/5 border-[#2FB36F]/25',
    blue: 'from-[#2D8CFF]/15 to-[#2D8CFF]/5 border-[#2D8CFF]/25',
    amber: 'from-[#FFC107]/25 to-[#FFC107]/5 border-[#FFC107]/40',
    neutral: 'from-muted to-background border-border',
    red: 'from-destructive/15 to-destructive/5 border-destructive/25',
};

const iconToneClass: Record<MetricTone, string> = {
    green: 'bg-[#2FB36F]/15 text-[#1f6b45]',
    blue: 'bg-[#2D8CFF]/15 text-[#1a5fbf]',
    amber: 'bg-[#FFC107]/25 text-[#8a6a00]',
    neutral: 'bg-muted text-foreground',
    red: 'bg-destructive/15 text-destructive',
};

type MetricCardProps = {
    label: string;
    value: ReactNode;
    hint?: string;
    icon?: LucideIcon;
    tone?: MetricTone;
    className?: string;
};

export function MetricCard({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'neutral',
    className,
}: MetricCardProps) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-gradient-to-br p-4 shadow-xs',
                toneClass[tone],
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p className="text-2xl font-semibold tracking-tight text-foreground">
                        {value}
                    </p>
                    {hint ? (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    ) : null}
                </div>
                {Icon ? (
                    <span
                        className={cn(
                            'inline-flex size-9 shrink-0 items-center justify-center rounded-lg',
                            iconToneClass[tone],
                        )}
                    >
                        <Icon className="size-4" aria-hidden />
                    </span>
                ) : null}
            </div>
        </div>
    );
}
