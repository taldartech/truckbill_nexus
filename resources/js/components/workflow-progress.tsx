import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

export type WorkflowStep = {
    id: string;
    label: string;
    status: 'complete' | 'current' | 'upcoming' | 'blocked';
};

type WorkflowProgressProps = {
    steps: WorkflowStep[];
    className?: string;
    'aria-label'?: string;
};

/**
 * Horizontal workflow indicator for Trip / Enquiry / Invoice lifecycles.
 */
export function WorkflowProgress({
    steps,
    className,
    'aria-label': ariaLabel = 'Workflow progress',
}: WorkflowProgressProps) {
    return (
        <ol
            aria-label={ariaLabel}
            className={cn(
                'grid gap-2 rounded-xl border border-border bg-card p-3 shadow-xs sm:grid-cols-2 lg:grid-cols-4',
                className,
            )}
        >
            {steps.map((step, index) => {
                const isComplete = step.status === 'complete';
                const isCurrent = step.status === 'current';
                const isBlocked = step.status === 'blocked';

                return (
                    <li
                        key={step.id}
                        className={cn(
                            'flex items-center gap-3 rounded-lg px-3 py-2',
                            isCurrent
                                ? 'bg-[color-mix(in_srgb,var(--brand-blue)_12%,white)]'
                                : null,
                            isBlocked ? 'opacity-60' : null,
                        )}
                    >
                        <span
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                isComplete
                                    ? 'bg-[var(--brand-green)] text-white'
                                    : isCurrent
                                      ? 'bg-[var(--brand-blue)] text-white'
                                      : isBlocked
                                        ? 'bg-destructive/15 text-destructive'
                                        : 'bg-muted text-muted-foreground',
                            )}
                            aria-hidden
                        >
                            {isComplete ? (
                                <Check className="size-4" />
                            ) : (
                                index + 1
                            )}
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium text-foreground">
                                {step.label}
                            </p>
                            <p className="text-xs text-muted-foreground capitalize">
                                {step.status === 'upcoming'
                                    ? 'Upcoming'
                                    : step.status}
                            </p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
