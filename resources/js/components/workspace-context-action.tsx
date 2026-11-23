import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';

type Props = {
    contextUrl: string;
    redirect: string;
    icon: LucideIcon;
    label: string;
    disabled?: boolean;
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
                        disabled={disabled || processing}
                        loading={processing}
                    />
                </>
            )}
        </Form>
    );
}
