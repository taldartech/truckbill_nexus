import * as LabelPrimitive from '@radix-ui/react-label';
import * as React from 'react';

import { cn } from '@/lib/utils';

function isControlRequired(control: Element | null): boolean {
    if (!control) {
        return false;
    }

    if (control.hasAttribute('disabled')) {
        return false;
    }

    if (
        control instanceof HTMLInputElement &&
        (control.type === 'checkbox' ||
            control.type === 'radio' ||
            control.type === 'hidden')
    ) {
        return false;
    }

    return (
        control.hasAttribute('required') ||
        control.getAttribute('aria-required') === 'true'
    );
}

function Label({
    className,
    required,
    children,
    htmlFor,
    ...props
}: React.ComponentProps<typeof LabelPrimitive.Root> & {
    required?: boolean;
}) {
    const labelRef = React.useRef<HTMLLabelElement>(null);
    const [inferredRequired, setInferredRequired] = React.useState(false);

    React.useLayoutEffect(() => {
        if (typeof required === 'boolean') {
            setInferredRequired(required);
            return undefined;
        }

        const resolveTarget = (): Element | null => {
            if (htmlFor) {
                return document.getElementById(htmlFor);
            }

            return (
                labelRef.current?.querySelector(
                    'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea, [role="combobox"]',
                ) ?? null
            );
        };

        let targetObserver: MutationObserver | null = null;
        let currentTarget: Element | null = null;

        const sync = () => {
            const nextTarget = resolveTarget();
            setInferredRequired(isControlRequired(nextTarget));

            if (nextTarget !== currentTarget) {
                targetObserver?.disconnect();
                currentTarget = nextTarget;
                if (currentTarget) {
                    targetObserver = new MutationObserver(sync);
                    targetObserver.observe(currentTarget, {
                        attributes: true,
                        attributeFilter: [
                            'required',
                            'aria-required',
                            'disabled',
                        ],
                    });
                }
            }
        };

        sync();

        const container = labelRef.current?.parentElement;
        const containerObserver = container
            ? new MutationObserver(sync)
            : null;
        if (container && containerObserver) {
            containerObserver.observe(container, {
                childList: true,
                subtree: true,
            });
        }

        return () => {
            targetObserver?.disconnect();
            containerObserver?.disconnect();
        };
    }, [htmlFor, required]);

    const showRequired =
        typeof required === 'boolean' ? required : inferredRequired;

    return (
        <LabelPrimitive.Root
            ref={labelRef}
            htmlFor={htmlFor}
            data-slot="label"
            data-required={showRequired ? 'true' : undefined}
            className={cn(
                'text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
                className,
            )}
            {...props}
        >
            {children}
            {showRequired ? (
                <span
                    data-slot="required-indicator"
                    className="ml-0.5 text-red-600 dark:text-red-500"
                    aria-hidden="true"
                >
                    *
                </span>
            ) : null}
        </LabelPrimitive.Root>
    );
}

export { Label };
