import * as React from 'react';

import { cn } from '@/lib/utils';

export type RadioGroupOption = {
    value: string;
    label: string;
};

type RadioGroupProps = {
    /** Radio `name`; use the real form field name when the group posts natively. */
    name: string;
    value: string;
    options: readonly RadioGroupOption[];
    onValueChange: (value: string) => void;
    /** Element id of the visible label for this group. */
    labelledBy?: string;
    required?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    className?: string;
    'data-testid'?: string;
};

function RadioGroup({
    name,
    value,
    options,
    onValueChange,
    labelledBy,
    required = false,
    disabled = false,
    invalid = false,
    className,
    'data-testid': testId,
}: RadioGroupProps) {
    return (
        <div
            role="radiogroup"
            aria-labelledby={labelledBy}
            aria-required={required || undefined}
            aria-invalid={invalid || undefined}
            data-slot="radio-group"
            data-testid={testId}
            className={cn(
                'flex min-h-9 flex-wrap items-center gap-x-6 gap-y-2',
                className,
            )}
        >
            {options.map((option) => (
                <label
                    key={option.value}
                    className={cn(
                        'inline-flex cursor-pointer items-center gap-2 text-sm text-foreground',
                        disabled && 'cursor-not-allowed opacity-50',
                    )}
                >
                    <input
                        type="radio"
                        name={name}
                        value={option.value}
                        checked={value === option.value}
                        onChange={() => onValueChange(option.value)}
                        required={required}
                        disabled={disabled}
                        className="size-4 cursor-pointer accent-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed"
                    />
                    {option.label}
                </label>
            ))}
        </div>
    );
}

export { RadioGroup };
