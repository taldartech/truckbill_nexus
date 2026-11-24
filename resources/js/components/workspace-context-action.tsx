import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import type { Button } from '@/components/ui/button';

type ButtonVariant = ComponentProps<typeof Button>['variant'];

type Props = {
    contextUrl: string;
    redirect: string;
    icon: LucideIcon;
    label: string;
    disabled?: boolean;
    /** Semantic action colour — forwarded to AppIconAction. */
    variant?: ButtonVariant;
};

/**
 * POST an authorized workspace context switch (route-bound company/branch),
 * then redirect to a clean URL. Never puts tenant IDs in the request body.
 */
export function WorkspaceContextAction({
    contextUrl,
    redirect,
    icon,
    label,
    disabled = false,
    variant = 'outline',
}: Props) {
    return (
        <Form action={contextUrl} method="post" className="inline">
            {({ processing }) => (
                <>
                    <input type="hidden" name="redirect" value={redirect} />
                    <AppIconAction
                        icon={icon}
                        label={label}
                        type="submit"
                        variant={variant}
                        disabled={disabled || processing}
                        loading={processing}
                    />
                </>
            )}
        </Form>
    );
}
