import type { LucideIcon } from 'lucide-react';

type Props = {
    label: string;
    value: string | number;
    hint?: string;
    icon: LucideIcon;
    tone?: 'default' | 'success' | 'warning' | 'info';
};

const toneClass: Record<NonNullable<Props['tone']>, string> = {
    default: 'bg-muted/50 text-foreground',
    success: 'bg-[#2FB36F]/10 text-[#1f8a54]',
    warning: 'bg-amber-50 text-amber-800',
    info: 'bg-[#2D8CFF]/10 text-[#1a6fd0]',
};

export function SummaryCard({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'default',
}: Props) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="space-y-1">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p className="text-2xl font-semibold tracking-tight tabular-nums">
                        {value}
                    </p>
                    {hint ? (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    ) : null}
                </div>
                <div
                    className={`flex size-10 items-center justify-center rounded-lg ${toneClass[tone]}`}
                >
                    <Icon className="size-5" aria-hidden />
                </div>
            </div>
        </div>
    );
}
