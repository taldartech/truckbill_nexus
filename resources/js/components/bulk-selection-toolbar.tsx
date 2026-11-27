import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    count: number;
    noun: string;
    onClear: () => void;
    /** Why individual actions are unavailable for this selection (shown instead of silently disabling them). */
    warnings?: string[];
    children: ReactNode;
};

/** Register bulk-action bar; selection may span pages and filters. */
export function BulkSelectionToolbar({
    count,
    noun,
    onClear,
    warnings = [],
    children,
}: Props) {
    if (count === 0) {
        return null;
    }

    return (
        <div
            role="region"
            aria-label="Selected rows"
            className="mx-4 mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-[#bae6fd] bg-[#f0f9ff] px-4 py-2 text-sm"
        >
            <span className="font-semibold text-slate-800">
                {count} {noun}
                {count === 1 ? '' : 's'} selected
            </span>
            <Button type="button" variant="ghost" size="sm" onClick={onClear}>
                Clear selection
            </Button>
            <div className="ms-auto flex flex-wrap gap-2">{children}</div>
            {warnings.length > 0 ? (
                <ul role="status" className="w-full space-y-0.5 text-amber-800">
                    {warnings.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
