import { formatDisplayDate, type DateInput } from '@/lib/dates';
import { cn } from '@/lib/utils';

export function DisplayDate({
    value,
    className,
}: {
    value: DateInput;
    className?: string;
}) {
    return (
        <span className={cn('text-nowrap tabular-nums', className)}>
            {formatDisplayDate(value)}
        </span>
    );
}
