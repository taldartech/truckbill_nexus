import * as React from 'react';

import { Input } from '@/components/ui/input';

export const MOBILE_NUMBER_LENGTH = 10;

export function sanitizeMobileNumber(value: string): string {
    return value.replace(/\D+/g, '').slice(0, MOBILE_NUMBER_LENGTH);
}

type MobileNumberInputProps = Omit<
    React.ComponentProps<'input'>,
    'type' | 'inputMode' | 'maxLength' | 'pattern'
>;

/**
 * Shared Indian mobile number input (Rules §29): digits only, at most ten.
 * Works controlled (`value` + `onChange`) and uncontrolled (`defaultValue` + `name`).
 */
function MobileNumberInput({ onChange, ...props }: MobileNumberInputProps) {
    return (
        <Input
            type="tel"
            inputMode="numeric"
            autoComplete="tel-national"
            maxLength={MOBILE_NUMBER_LENGTH}
            pattern="[6-9][0-9]{9}"
            title="Enter a valid 10-digit mobile number."
            {...props}
            onChange={(event) => {
                const sanitized = sanitizeMobileNumber(
                    event.currentTarget.value,
                );
                if (sanitized !== event.currentTarget.value) {
                    event.currentTarget.value = sanitized;
                }
                onChange?.(event);
            }}
        />
    );
}

export { MobileNumberInput };
